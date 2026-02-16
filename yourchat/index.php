<?php
require_once "/var/www/config/twitch.php";
require_once "/var/www/config/database.php";

session_start();

// Handle OAuth callback from StreamersConnect
if (isset($_GET['auth_data']) || isset($_GET['auth_data_sig']) || isset($_GET['server_token'])) {
    $authData = null;
    $cfg = require_once "/var/www/config/main.php";
    $apiKey = isset($cfg['streamersconnect_api_key']) ? $cfg['streamersconnect_api_key'] : '';
    // Prefer signed verification via StreamersConnect verify endpoint when an API key is configured
    if (isset($_GET['auth_data_sig']) && $apiKey) {
        $sig = $_GET['auth_data_sig'];
        $ch = curl_init('https://streamersconnect.com/verify_auth_sig.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['auth_data_sig' => $sig]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey
        ]);
        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response && $http === 200) {
            $res = json_decode($response, true);
            if (!empty($res['success']) && !empty($res['payload'])) {
                $authData = $res['payload'];
            }
        }
    }
    // If a server_token was provided, try exchanging server-side
    if (!$authData && isset($_GET['server_token']) && $apiKey) {
        $token = $_GET['server_token'];
        $ch = curl_init('https://streamersconnect.com/token_exchange.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['server_token' => $token]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey
        ]);
        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response && $http === 200) {
            $res = json_decode($response, true);
            if (!empty($res['success']) && !empty($res['payload'])) {
                $authData = $res['payload'];
            }
        }
    }
    // Fallback to legacy base64 encoded payload
    if (!$authData && isset($_GET['auth_data'])) {
        $authData = json_decode(base64_decode($_GET['auth_data']), true);
    }

    if (isset($authData['success']) && $authData['success'] && $authData['service'] === 'twitch') {
        $_SESSION['access_token'] = $authData['access_token'];
        $_SESSION['refresh_token'] = $authData['refresh_token'];
        $_SESSION['token_created_at'] = time();
        // Store user info from StreamersConnect response
        if (isset($authData['user'])) {
            $_SESSION['user_id'] = $authData['user']['id'];
            $_SESSION['user_login'] = $authData['user']['login'];
            $_SESSION['user_display_name'] = $authData['user']['display_name'];
        }
        // Prevent session fixation after successful OAuth
        session_regenerate_id(true);
    }
    // Redirect to clean URL
    header('Location: https://yourchat.botofthespecter.com/index.php');
    exit;
}

// Handle OAuth error from StreamersConnect
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
    $errorDescription = htmlspecialchars($_GET['error_description'] ?? 'Authentication failed');
    // You could display this error to the user or log it
    // For now, just redirect to login
    header('Location: https://yourchat.botofthespecter.com/index.php');
    exit;
}

// Handle token refresh via AJAX
if (isset($_GET['action']) && $_GET['action'] === 'refresh_token') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['refresh_token'])) {
        echo json_encode(['success' => false, 'error' => 'No refresh token']);
        exit;
    }
    $token_url = 'https://id.twitch.tv/oauth2/token';
    $token_data = [
        'client_id' => $clientID,
        'client_secret' => $clientSecret,
        'refresh_token' => $_SESSION['refresh_token'],
        'grant_type' => 'refresh_token'
    ];
    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($token_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $response = curl_exec($ch);
    $curlErr = null;
    if ($response === false) {
        $curlErr = curl_error($ch);
    }
    curl_close($ch);
    if ($response === false) {
        echo json_encode(['success' => false, 'error' => 'cURL error: ' . $curlErr]);
        exit;
    }
    $token_response = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON from token endpoint: ' . json_last_error_msg()]);
        exit;
    }
    if (isset($token_response['access_token'])) {
        $_SESSION['access_token'] = $token_response['access_token'];
        $_SESSION['refresh_token'] = $token_response['refresh_token'];
        $_SESSION['token_created_at'] = time();
        echo json_encode([
            'success' => true,
            'access_token' => $token_response['access_token'],
            'created_at' => $_SESSION['token_created_at']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Token refresh failed']);
    }
    exit;
}

// Handle session expiry
if (isset($_GET['action']) && $_GET['action'] === 'expire_session') {
    header('Content-Type: application/json');
    // Clear session data and cookie
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Session expired']);
    exit;
}

// Handle settings load
if (isset($_GET['action']) && $_GET['action'] === 'load_settings') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    $userId = $_SESSION['user_id'];
    $settingsFile = __DIR__ . '/user-settings/' . $userId . '.json';
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true);
        echo json_encode(['success' => true, 'settings' => $settings]);
    } else {
        // Return empty settings structure
        echo json_encode([
            'success' => true,
            'settings' => [
                'filters_usernames' => [],
                'filters_messages' => [],
                'nicknames' => new stdClass(),
                'presence_enabled' => false
            ]
        ]);
    }
    exit;
}

// Handle raw chat log
if (isset($_GET['action']) && $_GET['action'] === 'log_raw_chat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    $userId = $_SESSION['user_id'];
    $logsDir = '/var/www/yourchat/chat-logs';
    $logFile = $logsDir . '/' . $userId . '_raw_chat.log';
    // Create directory if it doesn't exist
    if (!is_dir($logsDir)) {
        if (!mkdir($logsDir, 0755, true)) {
            echo json_encode(['success' => false, 'error' => 'Failed to create logs directory']);
            exit;
        }
    }
    // Get JSON data from request body
    $jsonData = file_get_contents('php://input');
    if (empty($jsonData)) {
        echo json_encode(['success' => false, 'error' => 'No data received']);
        exit;
    }
    // Validate JSON
    $data = json_decode($jsonData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit;
    }
    // Append to log file with timestamp
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n" . str_repeat('-', 80) . "\n";
    if (file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to write to log file']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

// Handle settings save
if (isset($_GET['action']) && $_GET['action'] === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    $userId = $_SESSION['user_id'];
    $settingsDir = __DIR__ . '/user-settings';
    $settingsFile = $settingsDir . '/' . $userId . '.json';
    // Create directory if it doesn't exist
    if (!is_dir($settingsDir)) {
        if (!mkdir($settingsDir, 0755, true)) {
            echo json_encode(['success' => false, 'error' => 'Failed to create settings directory']);
            exit;
        }
    }
    // Get JSON data from request body
    $jsonData = file_get_contents('php://input');
    if (empty($jsonData)) {
        echo json_encode(['success' => false, 'error' => 'No data received']);
        exit;
    }
    $settings = json_decode($jsonData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit;
    }
    // Ensure nicknames is an object, not an array
    if (isset($settings['nicknames']) && is_array($settings['nicknames']) && empty($settings['nicknames'])) {
        $settings['nicknames'] = new stdClass();
    }
    // Save settings to file with JSON_FORCE_OBJECT for empty arrays in nicknames
    $jsonOutput = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($jsonOutput === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to encode settings: ' . json_last_error_msg()]);
        exit;
    }
    // Fix empty arrays to empty objects for nicknames field only
    $decoded = json_decode($jsonOutput, true);
    if (isset($decoded['nicknames']) && is_array($decoded['nicknames']) && empty($decoded['nicknames'])) {
        $jsonOutput = str_replace('"nicknames": []', '"nicknames": {}', $jsonOutput);
    }
    if (file_put_contents($settingsFile, $jsonOutput) === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to write file. Check permissions.']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Settings saved']);
    exit;
}

// Handle chat history load
if (isset($_GET['action']) && $_GET['action'] === 'load_chat_history') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    $userId = $_SESSION['user_id'];
    $logsDir = '/var/www/yourchat/chat-logs';
    $historyFile = $logsDir . '/' . $userId . '_chat_history.json';
    if (file_exists($historyFile)) {
        $historyData = file_get_contents($historyFile);
        $messages = json_decode($historyData, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($messages)) {
            echo json_encode(['success' => true, 'messages' => $messages]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid history file']);
        }
    } else {
        echo json_encode(['success' => true, 'messages' => []]);
    }
    exit;
}

// Handle chat history save
if (isset($_GET['action']) && $_GET['action'] === 'save_chat_history' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    $userId = $_SESSION['user_id'];
    $logsDir = '/var/www/yourchat/chat-logs';
    $historyFile = $logsDir . '/' . $userId . '_chat_history.json';
    // Create directory if it doesn't exist
    if (!is_dir($logsDir)) {
        if (!mkdir($logsDir, 0755, true)) {
            echo json_encode(['success' => false, 'error' => 'Failed to create logs directory']);
            exit;
        }
    }
    // Get JSON data from request body
    $jsonData = file_get_contents('php://input');
    if (empty($jsonData)) {
        echo json_encode(['success' => false, 'error' => 'No data received']);
        exit;
    }
    $messages = json_decode($jsonData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit;
    }
    // Save to file
    if (file_put_contents($historyFile, json_encode($messages, JSON_UNESCAPED_UNICODE)) === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to write history file']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

// Handle chat history clear
if (isset($_GET['action']) && $_GET['action'] === 'clear_chat_history' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    $userId = $_SESSION['user_id'];
    $logsDir = '/var/www/yourchat/chat-logs';
    $historyFile = $logsDir . '/' . $userId . '_chat_history.json';
    // Delete the file if it exists
    if (file_exists($historyFile)) {
        if (!unlink($historyFile)) {
            echo json_encode(['success' => false, 'error' => 'Failed to delete history file']);
            exit;
        }
    }
    echo json_encode(['success' => true, 'message' => 'Chat history cleared']);
    exit;
}

// Handle activity feed load
if (isset($_GET['action']) && $_GET['action'] === 'load_activity_feed') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    $userId = $_SESSION['user_id'];
    $logsDir = '/var/www/yourchat/activity-logs';
    $activityFile = $logsDir . '/' . $userId . '_activity_feed.json';
    if (file_exists($activityFile)) {
        $activities = json_decode(file_get_contents($activityFile), true);
        echo json_encode(['success' => true, 'activities' => $activities ?? []]);
    } else {
        echo json_encode(['success' => true, 'activities' => []]);
    }
    exit;
}

// Handle activity feed save
if (isset($_GET['action']) && $_GET['action'] === 'save_activity_feed' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    $userId = $_SESSION['user_id'];
    $logsDir = '/var/www/yourchat/activity-logs';
    $activityFile = $logsDir . '/' . $userId . '_activity_feed.json';
    // Create directory if it doesn't exist
    if (!is_dir($logsDir)) {
        if (!mkdir($logsDir, 0755, true)) {
            echo json_encode(['success' => false, 'error' => 'Failed to create directory']);
            exit;
        }
    }
    // Get JSON data from request body
    $jsonData = file_get_contents('php://input');
    if (empty($jsonData)) {
        echo json_encode(['success' => false, 'error' => 'No data received']);
        exit;
    }
    $activities = json_decode($jsonData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit;
    }
    // Save to file
    if (file_put_contents($activityFile, json_encode($activities, JSON_UNESCAPED_UNICODE)) === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to write to file']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

// Handle activity feed clear
if (isset($_GET['action']) && $_GET['action'] === 'clear_activity_feed' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    $userId = $_SESSION['user_id'];
    $logsDir = '/var/www/yourchat/activity-logs';
    $activityFile = $logsDir . '/' . $userId . '_activity_feed.json';
    // Delete the file if it exists
    if (file_exists($activityFile)) {
        if (!unlink($activityFile)) {
            echo json_encode(['success' => false, 'error' => 'Failed to delete activity file']);
            exit;
        }
    }
    echo json_encode(['success' => true, 'message' => 'Activity feed cleared']);
    exit;
}

// Handle EventSub session ID save
if (isset($_GET['action']) && $_GET['action'] === 'save_session_id' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['access_token']) || !isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    // Get JSON data from request body
    $jsonData = file_get_contents('php://input');
    if (empty($jsonData)) {
        echo json_encode(['success' => false, 'error' => 'No data received']);
        exit;
    }
    $data = json_decode($jsonData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit;
    }
    if (!isset($data['session_id']) || empty($data['session_id'])) {
        echo json_encode(['success' => false, 'error' => 'Session ID is required']);
        exit;
    }
    $sessionId = $data['session_id'];
    // Get username from Twitch API
    $ch = curl_init('https://api.twitch.tv/helix/users?id=' . urlencode($_SESSION['user_id']));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $_SESSION['access_token'],
        'Client-Id: ' . $clientID
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 || !$response) {
        echo json_encode(['success' => false, 'error' => 'Failed to get user info from Twitch']);
        exit;
    }
    $userData = json_decode($response, true);
    if (!isset($userData['data'][0]['login'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid user data from Twitch']);
        exit;
    }
    $username = $userData['data'][0]['login'];
    // Connect to user's database
    try {
        $usrDBconn = new mysqli($db_servername, $db_username, $db_password, $username);
        if ($usrDBconn->connect_error) {
            echo json_encode(['success' => false, 'error' => 'Database connection failed']);
            exit;
        }
        // Save session ID to database
        $stmt = $usrDBconn->prepare(
            "INSERT IGNORE INTO eventsub_sessions (session_id, session_name) VALUES (?, 'YourChat.BOTSpecter.com')"
        );
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Failed to prepare statement']);
            $usrDBconn->close();
            exit;
        }
        $stmt->bind_param('s', $sessionId);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Session ID saved']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save session ID']);
        }
        $stmt->close();
        $usrDBconn->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

$isLoggedIn = isset($_SESSION['access_token']) && isset($_SESSION['user_id']);

// Cache busting for CSS file using file modification time
$cssFile = __DIR__ . '/style.css';
$cssVersion = file_exists($cssFile) ? filemtime($cssFile) : time();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>YourChat - Custom Twitch Chat Overlay</title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="style.css?v=<?php echo $cssVersion; ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
</head>

<body>
    <div class="container">
        <?php if (!$isLoggedIn): ?>
            <div class="login-container">
                <h1>YourChat - Custom Twitch Chat Overlay</h1>
                <p class="login-subtitle">Login with Twitch to customize your chat overlay</p>
                <?php
                $scopes = 'user:read:chat user:write:chat chat:read chat:edit channel:read:redemptions moderator:read:chatters bits:read';
                $authUrl = 'https://streamersconnect.com/?' . http_build_query([
                    'service' => 'twitch',
                    'login' => 'yourchat.botofthespecter.com',
                    'scopes' => $scopes,
                    'return_url' => 'https://yourchat.botofthespecter.com/index.php'
                ]);
                ?>
                <a href="<?php echo htmlspecialchars($authUrl); ?>" class="login-btn">Login with Twitch</a>
                <p class="info-text" style="margin-top: 1rem; font-size: 0.9rem; opacity: 0.7;">Authentication powered by
                    StreamersConnect</p>
            </div>
        <?php else: ?>
            <div class="header">
                <div>
                    <h1>YourChat Overlay</h1>
                    <p>Logged in as <?php echo htmlspecialchars($_SESSION['user_display_name']); ?></p>
                </div>
                <div class="status-bar">
                    <div class="status-indicator">
                        <span class="status-light" id="ws-status"></span>
                        <span id="ws-status-text" class="hide-on-narrow">Disconnected</span>
                    </div>
                    <div class="status-indicator">
                        <span class="token-wrapper"><span class="token-label hide-on-narrow">Token:</span> <span
                                id="token-timer">--:--</span></span>
                    </div>
                    <div class="compact-actions" aria-hidden="false">
                        <button class="clear-history-btn" onclick="clearChatHistory()" title="Clear Chat History"
                            aria-label="Clear chat history">🗑️</button>
                        <button class="fullscreen-btn" onclick="toggleFullscreen()" title="Toggle Fullscreen"
                            aria-label="Toggle fullscreen"><span id="fullscreen-icon">⛶</span></button>
                        <button class="clear-history-btn" onclick="logoutUser()" title="Logout"
                            aria-label="Logout">Logout</button>
                    </div>
                </div>
            </div>
            <div class="two-column-container">
                <div class="settings-panel">
                    <h3>Import / Export</h3>
                    <p class="settings-description">Export your filters to a file or import from a previously saved file.
                    </p>
                    <div style="display:flex; gap:8px; margin-bottom:10px;">
                        <button class="clear-history-btn" id="export-filters-btn">Export Filters</button>
                        <button class="clear-history-btn" id="open-import-filters-btn">Import Filters</button>
                    </div>
                    <!-- Inline import menu shown under Export/Import buttons -->
                    <div id="import-filters-panel-inline" style="display:none; margin-top:8px;">
                        <div style="margin-bottom:8px;">
                            <textarea id="import-filters-textarea"
                                placeholder='Paste filters JSON here (e.g. {"usernames":[...],"messages":[...]}) or leave empty to attempt legacy import'
                                rows="6"
                                style="width:100%; box-sizing:border-box; resize:vertical; padding:8px; font-family:monospace;"></textarea>
                        </div>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <button class="clear-history-btn" id="import-filters-btn">Import Messages</button>
                            <button class="clear-history-btn" id="cancel-import-filters-btn">Cancel</button>
                        </div>
                    </div>
                </div>
                <div class="chat-features-panel settings-panel">
                    <h3>Chat Features</h3>
                    <p class="settings-description" style="margin-bottom:8px;">Optional chat UI features you can enable.</p>
                    <label class="feature-item">
                        <input type="checkbox" id="notify-joins-checkbox">&nbsp;Show join/leave notifications
                    </label>
                </div>
            </div>
            <div class="two-column-container">
                <div class="settings-panel">
                    <div class="filters-header">
                        <h3>Filters</h3>
                    </div>
                    <p class="settings-description">
                        Manage username and message filters separately. Each section can be collapsed.
                    </p>
                    <div class="sub-filters">
                        <div class="sub-panel">
                            <div class="filters-header">
                                <h4>Username Filters</h4>
                                <button id="toggle-filters-users-btn" class="toggle-btn" data-target="filter-list-users"
                                    aria-expanded="true">Hide</button>
                            </div>
                            <div id="filters-users-body" class="filters-body">
                                <input type="text" id="filter-user-input" class="filter-input"
                                    placeholder="Enter username to filter (press Enter to add)"
                                    onkeypress="handleFilterInput(event, 'user')">
                                <div class="filter-list" id="filter-list-users"></div>
                            </div>
                        </div>
                        <div class="sub-panel">
                            <div class="filters-header">
                                <h4>Message Filters</h4>
                                <button id="toggle-filters-msg-btn" class="toggle-btn" data-target="filter-list-msg"
                                    aria-expanded="true">Hide</button>
                            </div>
                            <div id="filters-msg-body" class="filters-body">
                                <input type="text" id="filter-msg-input" class="filter-input"
                                    placeholder="Enter phrase to filter (press Enter to add)"
                                    onkeypress="handleFilterInput(event, 'message')">
                                <div class="filter-list" id="filter-list-msg"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="settings-panel">
                    <div class="filters-header">
                        <h3>Nickname Management</h3>
                        <button id="toggle-nicknames-btn" class="toggle-btn" data-target="nickname-list"
                            aria-expanded="true">Hide</button>
                    </div>
                    <p class="settings-description">Set custom nicknames for chatters. Nicknames are tied to user IDs and
                        persist even if they change their username.</p>
                    <div style="display:flex; gap:8px; margin-bottom:10px;">
                        <input type="text" id="nickname-username" class="filter-input" placeholder="Enter Twitch username"
                            style="flex:1;">
                        <input type="text" id="nickname-value" class="filter-input" placeholder="Enter nickname"
                            style="flex:1;">
                        <button onclick="addNickname()"
                            style="padding:10px 20px; background:#9147ff; color:white; border:none; border-radius:8px; cursor:pointer;">Add
                            Nickname</button>
                    </div>
                    <div id="nickname-list" class="filter-list"></div>
                </div>
            </div>
        </div>
        <div class="chat-overlay" id="chat-overlay">
            <button class="fullscreen-exit-btn" id="fullscreen-exit" onclick="toggleFullscreen()"
                title="Exit Fullscreen (ESC)">
                ✕
            </button>
            <p class="chat-placeholder">Connecting to chat...</p>
        </div>
        <div class="message-input-container" id="message-input-container">
            <input type="text" id="message-input" class="message-input" placeholder="Send a message to your chat..."
                maxlength="500">
            <button class="send-message-btn" id="send-message-btn" onclick="sendChatMessage()"
                title="Send Message">Send</button>
        </div>
        <div class="activity-feed-container" id="activity-feed-container">
            <div class="activity-feed-header">
                <h3>✨ Activity Feed</h3>
                <div class="activity-feed-controls">
                    <button class="activity-feed-toggle-btn" id="activity-feed-toggle" onclick="toggleActivityFeed()" title="Collapse Activity Feed">−</button>
                    <button class="activity-feed-clear-btn" id="activity-feed-clear" onclick="clearActivityFeed()" title="Clear Activity Feed">🗑️</button>
                </div>
            </div>
            <div class="activity-feed-scroll" id="activity-feed-scroll">
                <div class="activity-feed-content" id="activity-feed">
                    <p class="activity-placeholder">No recent activity</p>
                </div>
            </div>
        </div>
        <script>
            // Configuration
            const CONFIG = {
                ACCESS_TOKEN: <?php echo json_encode($_SESSION['access_token'] ?? null); ?>,
                USER_ID: <?php echo json_encode($_SESSION['user_id'] ?? null); ?>,
                USER_LOGIN: <?php echo json_encode($_SESSION['user_login'] ?? null); ?>,
                TOKEN_CREATED_AT: <?php echo json_encode($_SESSION['token_created_at'] ?? time()); ?>,
                CLIENT_ID: <?php echo json_encode($clientID); ?>,
                TOKEN_REFRESH_INTERVAL: 4 * 60 * 60 * 1000, // 4 hours in milliseconds
                IRC_WS_URL: 'wss://irc-ws.chat.twitch.tv:443',
                EVENTSUB_WS_URL: 'wss://eventsub.wss.twitch.tv/ws'
            }; 
            // State management - Dual WebSocket connections
            let ircWs = null; // IRC WebSocket for chat messages
            let eventSubWs = null; // EventSub WebSocket for activity events
            let ircAuthenticated = false;
            let ircJoined = false;
            let eventSubSessionId = null;
            let eventSubConnected = false;
            let connectionErrorToast = null;
            let accessToken = CONFIG.ACCESS_TOKEN;
            let tokenCreatedAt = CONFIG.TOKEN_CREATED_AT;
            let tokenExpiresIn = null;
            let tokenValidatedAt = null;
            let tokenScopes = []; // Store token scopes
            let ircReconnectAttempts = 0;
            let eventSubReconnectAttempts = 0;
            let maxReconnectAttempts = 5;
            let pingTimeoutHandle = null;
            let pingTimeoutSeconds = 300; // IRC PING timeout
            let keepaliveTimeoutHandle = null;
            let keepaliveTimeoutSeconds = 10; // EventSub keepalive
            let badgeCache = {};
            let messageBuffer = [];
            let isReconnecting = false;
            // Recent redemptions cache to deduplicate matching chat messages
            let recentRedemptions = [];
            // Recent chat messages cache for bidirectional deduplication
            let recentChatMessages = [];
            // Recent bits events cache to deduplicate matching chat messages
            let recentBitsEvents = [];
            function addRecentChatMessage(user_login, user_name, user_id, text) {
                const entry = {
                    user_login: user_login ? String(user_login).toLowerCase() : null,
                    user_name: user_name ? String(user_name).toLowerCase() : null,
                    user_id: user_id ? String(user_id) : null,
                    text: text ? String(text).trim() : '',
                    ts: Date.now()
                };
                console.log('Added chat message to cache:', entry);
                recentChatMessages.push(entry);
                // Trim entries older than 10s
                const cutoff = Date.now() - 10000;
                recentChatMessages = recentChatMessages.filter(e => e.ts >= cutoff);
                console.log('Recent chat messages cache now has', recentChatMessages.length, 'entries');
            }
            function addRecentRedemption(user_login, user_name, user_id, text) {
                const entry = {
                    user_login: user_login ? String(user_login).toLowerCase() : null,
                    user_name: user_name ? String(user_name).toLowerCase() : null,
                    user_id: user_id ? String(user_id) : null,
                    text: text ? String(text).trim() : '',
                    ts: Date.now()
                };
                console.log('Added redemption to cache:', entry);
                recentRedemptions.push(entry);
                // Trim entries older than 10s
                const cutoff = Date.now() - 10000;
                recentRedemptions = recentRedemptions.filter(e => e.ts >= cutoff);
                console.log('Recent redemptions cache now has', recentRedemptions.length, 'entries');
            }
            function addRecentBitsEvent(user_login, user_name, user_id, message) {
                const entry = {
                    user_login: user_login ? String(user_login).toLowerCase() : null,
                    user_name: user_name ? String(user_name).toLowerCase() : null,
                    user_id: user_id ? String(user_id) : null,
                    message: message ? String(message).trim() : '',
                    ts: Date.now()
                };
                console.log('Added bits event to cache:', entry);
                recentBitsEvents.push(entry);
                // Trim entries older than 10s
                const cutoff = Date.now() - 10000;
                recentBitsEvents = recentBitsEvents.filter(e => e.ts >= cutoff);
                console.log('Recent bits events cache now has', recentBitsEvents.length, 'entries');
            }
            function consumeMatchingRedemption(chatter_login, chatter_name, chatter_id, text) {
                if (!text) return false;
                const t = String(text).trim();
                const login = chatter_login ? String(chatter_login).toLowerCase() : null;
                const name = chatter_name ? String(chatter_name).toLowerCase() : null;
                const id = chatter_id ? String(chatter_id) : null;
                const now = Date.now();
                console.log('Looking for matching redemption:', { login, name, id, text: t, cacheSize: recentRedemptions.length });
                // Consider matches within last 5 seconds
                for (let i = 0; i < recentRedemptions.length; i++) {
                    const e = recentRedemptions[i];
                    if (now - e.ts > 5000) continue;
                    console.log('Checking against:', e, 'age:', (now - e.ts) + 'ms');
                    // Match if text is identical AND any of: login, name, or id matches
                    if (e.text === t && (
                        (login && e.user_login === login) ||
                        (name && e.user_name === name) ||
                        (id && e.user_id === id)
                    )) {
                        // remove this entry and return true
                        console.log('FOUND MATCH! Consuming redemption and suppressing chat message');
                        recentRedemptions.splice(i, 1);
                        return true;
                    }
                }
                console.log('No matching redemption found');
                return false;
            }
            function consumeMatchingChatMessage(user_login, user_name, user_id, text) {
                if (!text) return false;
                const t = String(text).trim();
                const login = user_login ? String(user_login).toLowerCase() : null;
                const name = user_name ? String(user_name).toLowerCase() : null;
                const id = user_id ? String(user_id) : null;
                const now = Date.now();
                console.log('Looking for matching chat message:', { login, name, id, text: t, cacheSize: recentChatMessages.length });
                // Consider matches within last 5 seconds
                for (let i = 0; i < recentChatMessages.length; i++) {
                    const e = recentChatMessages[i];
                    if (now - e.ts > 5000) continue;
                    console.log('Checking chat message against:', e, 'age:', (now - e.ts) + 'ms');
                    // Match if text is identical AND any of: login, name, or id matches
                    if (e.text === t && (
                        (login && e.user_login === login) ||
                        (name && e.user_name === name) ||
                        (id && e.user_id === id)
                    )) {
                        recentChatMessages.splice(i, 1);
                        return true;
                    }
                }
                console.log('No matching chat message found');
                return false;
            }
            function consumeMatchingBitsEvent(chatter_login, chatter_name, chatter_id, text) {
                if (!text) return false;
                const t = String(text).trim();
                const login = chatter_login ? String(chatter_login).toLowerCase() : null;
                const name = chatter_name ? String(chatter_name).toLowerCase() : null;
                const id = chatter_id ? String(chatter_id) : null;
                const now = Date.now();
                console.log('Looking for matching bits event:', { login, name, id, text: t, cacheSize: recentBitsEvents.length });
                // Consider matches within last 5 seconds
                for (let i = 0; i < recentBitsEvents.length; i++) {
                    const e = recentBitsEvents[i];
                    if (now - e.ts > 5000) continue;
                    console.log('Checking against bits event:', e, 'age:', (now - e.ts) + 'ms');
                    // Match if message is identical AND any of: login, name, or id matches
                    if (e.message === t && (
                        (login && e.user_login === login) ||
                        (name && e.user_name === name) ||
                        (id && e.user_id === id)
                    )) {
                        // remove this entry and return true
                        console.log('FOUND MATCH! Consuming bits event and suppressing chat message');
                        recentBitsEvents.splice(i, 1);
                        return true;
                    }
                }
                console.log('No matching bits event found');
                return false;
            }
            // Presence settings (IRC-based)
            const PRESENCE_JOIN_KEY = 'notify_join_leave';
            let presenceEnabled = false;
            let activeChatters = new Set(); // Users currently in chat (tracked via IRC JOIN/PART)
            let messageBasedChatters = new Set(); // Users detected via first message only
            // Max chat messages to retain in overlay (match Twitch default of 50)
            const MAX_CHAT_MESSAGES = 50;
            function loadPresenceSetting() {
                return userSettings.presence_enabled || false;
            }
            function savePresenceSetting(enabled) {
                userSettings.presence_enabled = enabled;
                saveSettingsToServer();
            }
            function showSystemMessage(text, kind) {
                const overlay = document.getElementById('chat-overlay');
                // Clear placeholder text if it's the only thing there
                if (overlay.children.length === 1 && overlay.children[0].tagName === 'P') {
                    clearPlaceholderOnly();
                }
                const div = document.createElement('div');
                div.className = 'system-message';
                if (kind) div.classList.add(kind);
                div.textContent = text;
                // Only presence summary messages go at the top; join/leave messages are chronological
                if (kind === 'join' || kind === 'leave') {
                    // Append join/leave messages at the bottom like regular chat messages
                    overlay.appendChild(div);
                    // Auto-scroll to show new join/leave messages
                    overlay.scrollTop = overlay.scrollHeight;
                } else {
                    // Other system messages (like presence summary) go at the top
                    const exitBtn = overlay.querySelector('.fullscreen-exit-btn');
                    const ref = exitBtn ? exitBtn.nextSibling : overlay.firstChild;
                    overlay.insertBefore(div, ref);
                }
                // Enforce messages cap (count only message nodes)
                // Enforce cap using helper (default 50 messages)
                enforceMessageCap(MAX_CHAT_MESSAGES);
            }
            // Update or insert a 'presence' system message (used for the "Currently in chat" summary)
            function setPresenceMessage(text) {
                const overlay = document.getElementById('chat-overlay');
                const exitBtn = overlay.querySelector('.fullscreen-exit-btn');
                // Look for an existing presence/system join message
                const existing = overlay.querySelector('.system-message.join');
                if (existing) {
                    existing.textContent = text;
                    return;
                }
                // No existing presence message, prepend one
                if ((overlay.children.length === 1 && overlay.children[0].tagName === 'P') || overlay.children.length === 0) {
                    clearPlaceholderOnly();
                    if (exitBtn && !overlay.contains(exitBtn)) overlay.appendChild(exitBtn);
                }
                const div = document.createElement('div');
                div.className = 'system-message join';
                div.textContent = text;
                const ref = exitBtn ? exitBtn.nextSibling : overlay.firstChild;
                overlay.insertBefore(div, ref);
            }
            // Remove only placeholder/connected informational nodes from the overlay to avoid wiping live messages
            function clearPlaceholderOnly() {
                const overlay = document.getElementById('chat-overlay');
                if (!overlay) return;
                // Remove explicit chat placeholder paragraphs and connected placeholder
                const placeholders = overlay.querySelectorAll('.chat-placeholder, .connected-placeholder');
                placeholders.forEach(p => {
                    try { p.remove(); } catch (e) { /* ignore */ }
                });
            }
            // Enforce a cap on the number of visible messages in the overlay
            // Removes oldest eligible messages (skips persistent UI elements like presence summary)
            function enforceMessageCap(maxMessages = MAX_CHAT_MESSAGES) {
                const overlay = document.getElementById('chat-overlay');
                if (!overlay) return;
                try { console.debug('enforceMessageCap called - ensuring max', maxMessages); } catch (e) { }
                const msgs = Array.from(overlay.querySelectorAll('.chat-message, .reward-message, .system-message'));
                // Filter out nodes that should not be removed
                const eligible = msgs.filter(node => {
                    if (!node) return false;
                    // presence summary and other important system messages should be preserved
                    if (node.classList.contains('system-message') && !node.classList.contains('join') && !node.classList.contains('leave') && !node.classList.contains('raid') && !node.classList.contains('bits')) return false;
                    if (node.classList.contains('fullscreen-exit-btn')) return false;
                    return true;
                });
                const excess = eligible.length - maxMessages;
                if (excess > 0) {
                    for (let i = 0; i < excess; i++) {
                        const node = eligible[i];
                        if (node && node.parentNode) {
                            try { node.parentNode.removeChild(node); } catch (e) { /* ignore */ }
                        }
                    }
                }
            }
            // Message-based presence removed — presence is provided via Helix API polling
            function extractTextFromEvent(event) {
                if (!event) return '';
                // Custom reward user input
                if (event.user_input) return String(event.user_input).trim();
                // Message object (chat or automatic reward)
                if (event.message) {
                    if (event.message.fragments && Array.isArray(event.message.fragments)) {
                        return event.message.fragments.map(f => f.text || '').join('').trim();
                    }
                    return String(event.message.text || '').trim();
                }
                return '';
            }
            // Fetch badge data from Twitch API
            async function fetchBadges() {
                try {
                    // Fetch global badges
                    const globalResponse = await fetch('https://api.twitch.tv/helix/chat/badges/global', {
                        headers: {
                            'Authorization': `Bearer ${accessToken}`,
                            'Client-Id': CONFIG.CLIENT_ID
                        }
                    });
                    const globalData = await globalResponse.json();
                    // Fetch channel-specific badges
                    const channelResponse = await fetch(`https://api.twitch.tv/helix/chat/badges?broadcaster_id=${CONFIG.USER_ID}`, {
                        headers: {
                            'Authorization': `Bearer ${accessToken}`,
                            'Client-Id': CONFIG.CLIENT_ID
                        }
                    });
                    const channelData = await channelResponse.json();
                    // Build badge cache
                    [...globalData.data, ...channelData.data].forEach(set => {
                        badgeCache[set.set_id] = {};
                        set.versions.forEach(version => {
                            badgeCache[set.set_id][version.id] = version.image_url_1x;
                        });
                    });
                } catch (error) {
                    console.error('Error fetching badges:', error);
                }
            }
            // Server-side settings management
            let userSettings = {
                filters_usernames: [],
                filters_messages: [],
                nicknames: {},
                presence_enabled: false
            };
            async function loadSettingsFromServer() {
                try {
                    const response = await fetch('index.php?action=load_settings');
                    const data = await response.json();
                    if (data.success && data.settings) {
                        userSettings = data.settings;
                        // Ensure all keys exist with correct types
                        if (!userSettings.filters_usernames) userSettings.filters_usernames = [];
                        if (!userSettings.filters_messages) userSettings.filters_messages = [];
                        // Ensure nicknames is always an object, never an array
                        if (!userSettings.nicknames || Array.isArray(userSettings.nicknames)) {
                            userSettings.nicknames = {};
                        }
                        if (userSettings.presence_enabled === undefined) userSettings.presence_enabled = false;
                        return true;
                    }
                    return false;
                } catch (e) {
                    console.error('Failed to load settings from server:', e);
                    return false;
                }
            }
            async function saveSettingsToServer() {
                try {
                    const response = await fetch('index.php?action=save_settings', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(userSettings)
                    });
                    const data = await response.json();
                    if (!data.success) {
                        console.error('Failed to save settings:', data.error || 'Unknown error');
                        // Show user-friendly error notification
                        Toastify({
                            text: 'Failed to save settings: ' + (data.error || 'Unknown error'),
                            duration: 5000,
                            gravity: 'top',
                            position: 'right',
                            backgroundColor: 'linear-gradient(to right, #ff416c, #ff4b2b)',
                        }).showToast();
                    }
                } catch (e) {
                    console.error('Failed to save settings to server:', e);
                    Toastify({
                        text: 'Network error saving settings',
                        duration: 5000,
                        gravity: 'top',
                        position: 'right',
                        backgroundColor: 'linear-gradient(to right, #ff416c, #ff4b2b)',
                    }).showToast();
                }
            }
            async function saveSessionIdToServer(sessionId) {
                try {
                    const response = await fetch('index.php?action=save_session_id', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ session_id: sessionId })
                    });
                    const data = await response.json();
                    if (data.success) {
                        console.log('Session ID saved to database:', sessionId);
                    } else {
                        console.error('Failed to save session ID:', data.error || 'Unknown error');
                    }
                } catch (e) {
                    console.error('Failed to save session ID to server:', e);
                }
            }
            // Load filters from cookies
            // Filters storage: separate username and message filters
            function loadFiltersUsernames() {
                return userSettings.filters_usernames || [];
            }
            function saveFiltersUsernames(list) {
                userSettings.filters_usernames = list;
                saveSettingsToServer();
            }
            function loadFiltersMessages() {
                return userSettings.filters_messages || [];
            }
            function saveFiltersMessages(list) {
                userSettings.filters_messages = list;
                saveSettingsToServer();
            }
            // Cookie helper function
            function getCookie(name) {
                const value = `; ${document.cookie}`;
                const parts = value.split(`; ${name}=`);
                if (parts.length === 2) return parts.pop().split(';').shift();
                return null;
            }
            // Migrate legacy combined filters (cookie/localStorage key 'chat_filters')
            function migrateOldFilters() {
                try {
                    // If new keys already have data, skip migration
                    const haveUsers = (window.localStorage && localStorage.getItem('chat_filters_usernames')) || getCookie('chat_filters_usernames');
                    const haveMsgs = (window.localStorage && localStorage.getItem('chat_filters_messages')) || getCookie('chat_filters_messages');
                    if (haveUsers || haveMsgs) return;
                    // Look for old combined storage
                    let legacy = null;
                    try { if (window.localStorage) legacy = localStorage.getItem('chat_filters'); } catch (e) { }
                    if (!legacy) legacy = getCookie('chat_filters');
                    if (!legacy) return;
                    const parsed = JSON.parse(legacy);
                    if (!Array.isArray(parsed) || parsed.length === 0) return;
                    // Migrate into message filters by default (safer)
                    saveFiltersMessages(parsed);
                    // Remove legacy cookie to avoid repeated migration
                    try { document.cookie = 'chat_filters=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/'; } catch (e) { }
                    try { if (window.localStorage) localStorage.removeItem('chat_filters'); } catch (e) { }
                    console.info('Migrated legacy chat_filters into message filters');
                } catch (e) {
                    console.warn('Failed to migrate old chat_filters', e);
                }
            }
            // Nickname management
            function loadNicknames() {
                // Ensure nicknames is always an object, never an array
                if (!userSettings.nicknames || Array.isArray(userSettings.nicknames)) {
                    userSettings.nicknames = {};
                }
                return userSettings.nicknames;
            }
            function saveNicknames(nicknames) {
                // Ensure nicknames is always an object
                if (Array.isArray(nicknames)) {
                    nicknames = {};
                }
                userSettings.nicknames = nicknames;
                saveSettingsToServer();
            }
            function renderNicknames() {
                const nicknames = loadNicknames();
                const container = document.getElementById('nickname-list');
                if (!container) return;
                container.innerHTML = '';
                Object.entries(nicknames).forEach(([userId, data]) => {
                    const tag = document.createElement('div');
                    tag.className = 'filter-tag';
                    tag.innerHTML = `
                        <span>${escapeHtml(data.username)} \u2192 ${escapeHtml(data.nickname)}</span>
                        <button onclick="removeNickname('${userId}')" title="Remove nickname">\u2715</button>
                    `;
                    container.appendChild(tag);
                });
            }
            async function addNickname() {
                const usernameInput = document.getElementById('nickname-username');
                const nicknameInput = document.getElementById('nickname-value');
                const username = usernameInput.value.trim().toLowerCase();
                const nickname = nicknameInput.value.trim();
                if (!username || !nickname) {
                    Toastify({
                        text: "Please enter both username and nickname",
                        duration: 3000,
                        gravity: "top",
                        position: "right",
                        backgroundColor: "linear-gradient(to right, #ff416c, #ff4b2b)",
                    }).showToast();
                    return;
                }
                // Fetch user ID from Twitch API
                try {
                    const response = await fetch(`https://api.twitch.tv/helix/users?login=${username}`, {
                        headers: {
                            'Authorization': `Bearer ${accessToken}`,
                            'Client-Id': CONFIG.CLIENT_ID
                        }
                    });
                    if (!response.ok) {
                        throw new Error('Failed to fetch user data');
                    }
                    const data = await response.json();
                    if (!data.data || data.data.length === 0) {
                        Toastify({
                            text: "User not found on Twitch",
                            duration: 3000,
                            gravity: "top",
                            position: "right",
                            backgroundColor: "linear-gradient(to right, #ff416c, #ff4b2b)",
                        }).showToast();
                        return;
                    }
                    const userId = data.data[0].id;
                    const nicknames = loadNicknames();
                    nicknames[userId] = {
                        username: username,
                        nickname: nickname
                    };
                    saveNicknames(nicknames);
                    renderNicknames();
                    usernameInput.value = '';
                    nicknameInput.value = '';
                    Toastify({
                        text: `Nickname set for ${username}`,
                        duration: 3000,
                        gravity: "top",
                        position: "right",
                        backgroundColor: "linear-gradient(to right, #00b09b, #96c93d)",
                    }).showToast();
                } catch (error) {
                    console.error('Error adding nickname:', error);
                    Toastify({
                        text: "Failed to add nickname. Please try again.",
                        duration: 3000,
                        gravity: "top",
                        position: "right",
                        backgroundColor: "linear-gradient(to right, #ff416c, #ff4b2b)",
                    }).showToast();
                }
            }
            function removeNickname(userId) {
                const nicknames = loadNicknames();
                delete nicknames[userId];
                saveNicknames(nicknames);
                renderNicknames();
                Toastify({
                    text: "Nickname removed",
                    duration: 2000,
                    gravity: "top",
                    position: "right",
                    backgroundColor: "linear-gradient(to right, #00b09b, #96c93d)",
                }).showToast();
            }
            function getNickname(userId) {
                const nicknames = loadNicknames();
                return nicknames[userId]?.nickname || null;
            }
            // Save chat history to server
            async function saveChatHistory() {
                try {
                    const overlay = document.getElementById('chat-overlay');
                    const messages = Array.from(overlay.children)
                        .filter(child => child.classList.contains('chat-message') || child.classList.contains('reward-message'))
                        .slice(-100) // Keep last 100 messages
                        .map(msg => btoa(unescape(encodeURIComponent(msg.outerHTML)))); // Base64 encode
                    const response = await fetch('?action=save_chat_history', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(messages)
                    });
                    const result = await response.json();
                    if (!result.success) {
                        console.error('Failed to save chat history:', result.error);
                    }
                } catch (error) {
                    console.error('Error saving chat history:', error);
                }
            }
            // Clear chat history
            async function clearChatHistory() {
                try {
                    const overlay = document.getElementById('chat-overlay');
                    // Remove all messages from DOM
                    const messages = overlay.querySelectorAll('.chat-message, .automatic-reward, .custom-reward');
                    messages.forEach(msg => msg.remove());
                    // Clear from server
                    const response = await fetch('?action=clear_chat_history', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' }
                    });
                    const result = await response.json();
                    if (!result.success) {
                        console.error('Failed to clear chat history:', result.error);
                    }
                    // Add placeholder if chat is empty
                    if (overlay.children.length === 0 || (overlay.children.length === 1 && overlay.children[0].classList.contains('fullscreen-exit-btn'))) {
                        const placeholder = document.createElement('p');
                        placeholder.className = 'chat-placeholder';
                        placeholder.textContent = 'Chat history cleared. Waiting for messages...';
                        overlay.appendChild(placeholder);
                    }
                } catch (error) {
                    console.error('Error clearing chat history:', error);
                }
            }
            // Load chat history from server
            async function loadChatHistory() {
                try {
                    const response = await fetch('?action=load_chat_history');
                    const result = await response.json();
                    if (result.success && result.messages && result.messages.length > 0) {
                        const overlay = document.getElementById('chat-overlay');
                        const hasOnlyPlaceholder = overlay.children.length === 0 ||
                            (overlay.children.length === 1 && overlay.children[0].tagName === 'P') ||
                            (overlay.children.length === 1 && overlay.children[0].classList.contains('fullscreen-exit-btn')) ||
                            (overlay.children.length === 2 && overlay.querySelector('.fullscreen-exit-btn') && overlay.querySelector('.chat-placeholder'));
                        if (hasOnlyPlaceholder) {
                            // Preserve any non-message nodes like fullscreen button
                            const exitBtn = overlay.querySelector('.fullscreen-exit-btn');
                            // Remove placeholder(s) but keep exit button
                            clearPlaceholderOnly();
                            if (exitBtn && !overlay.contains(exitBtn)) overlay.appendChild(exitBtn);
                            // Restore messages
                            result.messages.forEach(encoded => {
                                try {
                                    const html = decodeURIComponent(escape(atob(encoded)));
                                    // Basic sanitization: skip entries that contain script tags or inline event handlers
                                    if (/<script\b|on\w+\s*=/.test(html)) {
                                        console.warn('Skipping suspicious history entry');
                                        return;
                                    }
                                    const div = document.createElement('div');
                                    div.innerHTML = html;
                                    overlay.appendChild(div.firstChild);
                                } catch (decodeError) {
                                    console.log('Skipping invalid history entry');
                                }
                            });
                            // Enforce cap after restoring history to match Twitch's behavior (keep last messages)
                            enforceMessageCap(MAX_CHAT_MESSAGES);
                            overlay.scrollTop = overlay.scrollHeight;
                        } else {
                            console.log('Skipping history restore - overlay already has live messages');
                        }
                    }
                } catch (error) {
                    console.error('Error loading chat history:', error);
                }
            }
            // Activity Feed Management
            let activityFeed = [];
            const MAX_ACTIVITY_ITEMS = 100;
            let activityFeedCollapsed = false;
            // Add activity to feed
            function addActivity(type, user, details) {
                const activity = {
                    type: type,
                    user: user,
                    details: details,
                    timestamp: new Date().toISOString(),
                    id: Date.now() + Math.random()
                };
                activityFeed.push(activity);
                // Trim to max items
                if (activityFeed.length > MAX_ACTIVITY_ITEMS) {
                    activityFeed = activityFeed.slice(-MAX_ACTIVITY_ITEMS);
                }
                renderActivity(activity);
                saveActivityFeed();
            }
            // Render a single activity item
            function renderActivity(activity) {
                const feedContainer = document.getElementById('activity-feed');
                const placeholder = feedContainer.querySelector('.activity-placeholder');
                if (placeholder) {
                    placeholder.remove();
                }
                const activityCard = document.createElement('div');
                activityCard.className = `activity-card activity-${activity.type}`;
                activityCard.dataset.id = activity.id;
                // Build activity card HTML
                let icon = '';
                let title = '';
                let description = '';
                switch(activity.type) {
                    case 'raid':
                        icon = '🎯';
                        title = 'Raid';
                        description = `<strong>${activity.user.name}</strong> raided with <strong>${activity.details.viewers}</strong> viewers!`;
                        break;
                    case 'cheer':
                        icon = '💎';
                        title = 'Cheer';
                        description = `<strong>${activity.user.name}</strong> cheered <strong>${activity.details.bits}</strong> bits!`;
                        if (activity.details.message) {
                            description += `<br><span class="activity-message">${escapeHtml(activity.details.message)}</span>`;
                        }
                        break;
                    case 'subscription':
                        icon = '⭐';
                        title = activity.details.is_gift ? 'Gift Sub' : 'Subscription';
                        if (activity.details.is_gift) {
                            description = `<strong>${activity.user.name}</strong> gifted a Tier ${activity.details.tier} sub!`;
                        } else {
                            description = `<strong>${activity.user.name}</strong> subscribed (Tier ${activity.details.tier})!`;
                            if (activity.details.cumulative_months > 1) {
                                description += ` ${activity.details.cumulative_months} months total!`;
                            }
                        }
                        break;
                    case 'follow':
                        icon = '❤️';
                        title = 'Follow';
                        description = `<strong>${activity.user.name}</strong> followed!`;
                        break;
                    case 'redemption':
                        icon = '🎁';
                        title = 'Channel Points';
                        description = `<strong>${activity.user.name}</strong> redeemed <strong>${activity.details.reward_title}</strong>`;
                        if (activity.details.cost) {
                            description += ` (${activity.details.cost} pts)`;
                        }
                        break;
                    case 'hypetrain':
                        icon = '🔥';
                        title = 'Hype Train';
                        description = `Hype Train Level <strong>${activity.details.level}</strong>! Progress: ${activity.details.progress}/${activity.details.goal}`;
                        break;
                }
                activityCard.innerHTML = `
                    <div class="activity-icon">${icon}</div>
                    <div class="activity-content">
                        ${activity.user.avatar ? `<img src="${activity.user.avatar}" class="activity-avatar" alt="${activity.user.name}">` : ''}
                        <div class="activity-details">
                            <div class="activity-description">${description}</div>
                            <div class="activity-timestamp">${getRelativeTime(activity.timestamp)}</div>
                        </div>
                    </div>
                `;
                feedContainer.appendChild(activityCard);
                // Scroll to the right (latest activity)
                const scrollContainer = document.getElementById('activity-feed-scroll');
                if (scrollContainer) {
                    scrollContainer.scrollLeft = scrollContainer.scrollWidth;
                }
                // Animate card entrance
                setTimeout(() => {
                    activityCard.classList.add('activity-entered');
                }, 10);
            }
            // Get relative time string
            function getRelativeTime(timestamp) {
                const now = new Date();
                const then = new Date(timestamp);
                const seconds = Math.floor((now - then) / 1000);
                
                if (seconds < 60) return 'just now';
                if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
                if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
                return `${Math.floor(seconds / 86400)}d ago`;
            }
            // Escape HTML to prevent XSS
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            // Toggle activity feed visibility
            function toggleActivityFeed() {
                const container = document.getElementById('activity-feed-container');
                const toggleBtn = document.getElementById('activity-feed-toggle');
                activityFeedCollapsed = !activityFeedCollapsed;
                if (activityFeedCollapsed) {
                    container.classList.add('collapsed');
                    toggleBtn.textContent = '+';
                    toggleBtn.title = 'Expand Activity Feed';
                } else {
                    container.classList.remove('collapsed');
                    toggleBtn.textContent = '−';
                    toggleBtn.title = 'Collapse Activity Feed';
                }
            }
            // Clear activity feed
            async function clearActivityFeed() {
                if (!confirm('Clear all activity feed items?')) return;
                try {
                    activityFeed = [];
                    const feedContainer = document.getElementById('activity-feed');
                    feedContainer.innerHTML = '<p class="activity-placeholder">No recent activity</p>';
                    const response = await fetch('?action=clear_activity_feed', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' }
                    });
                    const result = await response.json();
                    if (!result.success) {
                        console.error('Failed to clear activity feed:', result.error);
                    }
                } catch (error) {
                    console.error('Error clearing activity feed:', error);
                }
            }
            // Save activity feed to server
            async function saveActivityFeed() {
                try {
                    const response = await fetch('?action=save_activity_feed', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(activityFeed)
                    });
                    const result = await response.json();
                    if (!result.success) {
                        console.error('Failed to save activity feed:', result.error);
                    }
                } catch (error) {
                    console.error('Error saving activity feed:', error);
                }
            }
            // Load activity feed from server
            async function loadActivityFeed() {
                try {
                    const response = await fetch('?action=load_activity_feed');
                    const result = await response.json();
                    if (result.success && result.activities && result.activities.length > 0) {
                        activityFeed = result.activities;
                        const feedContainer = document.getElementById('activity-feed');
                        feedContainer.innerHTML = '';
                        activityFeed.forEach(activity => {
                            renderActivity(activity);
                        });
                        // Update timestamps periodically
                        updateActivityTimestamps();
                    }
                } catch (error) {
                    console.error('Error loading activity feed:', error);
                }
            }
            // Update timestamps in activity feed
            function updateActivityTimestamps() {
                const feedContainer = document.getElementById('activity-feed');
                const activityCards = feedContainer.querySelectorAll('.activity-card');
                activityCards.forEach(card => {
                    const id = card.dataset.id;
                    const activity = activityFeed.find(a => a.id == id);
                    if (activity) {
                        const timestampEl = card.querySelector('.activity-timestamp');
                        if (timestampEl) {
                            timestampEl.textContent = getRelativeTime(activity.timestamp);
                        }
                    }
                });
            }
            // Update timestamps every minute
            setInterval(updateActivityTimestamps, 60000);
            // Filter management
            function renderFilters() {
                // usernames
                const users = loadFiltersUsernames();
                const listUsers = document.getElementById('filter-list-users');
                listUsers.innerHTML = '';
                users.forEach(filter => {
                    const tag = document.createElement('div');
                    tag.className = 'filter-tag';
                    const label = document.createElement('span');
                    label.textContent = filter;
                    const btn = document.createElement('button');
                    btn.title = 'Remove filter';
                    btn.textContent = '×';
                    btn.addEventListener('click', () => removeFilter('user', filter));
                    tag.appendChild(label);
                    tag.appendChild(btn);
                    listUsers.appendChild(tag);
                });
                // messages
                const msgs = loadFiltersMessages();
                const listMsgs = document.getElementById('filter-list-msg');
                listMsgs.innerHTML = '';
                msgs.forEach(filter => {
                    const tag = document.createElement('div');
                    tag.className = 'filter-tag';
                    const label = document.createElement('span');
                    label.textContent = filter;
                    const btn = document.createElement('button');
                    btn.title = 'Remove filter';
                    btn.textContent = '×';
                    btn.addEventListener('click', () => removeFilter('message', filter));
                    tag.appendChild(label);
                    tag.appendChild(btn);
                    listMsgs.appendChild(tag);
                });
            }
        function handleFilterInput(event, type) {
            if (event.key !== 'Enter') return;
            const inputId = type === 'user' ? 'filter-user-input' : 'filter-msg-input';
            const input = document.getElementById(inputId);
            if (!input) return;
            const value = input.value.trim();
            if (!value) return;
            if (type === 'user') {
                const filters = loadFiltersUsernames();
                if (!filters.includes(value)) {
                    filters.push(value);
                    saveFiltersUsernames(filters);
                }
            } else {
                const filters = loadFiltersMessages();
                if (!filters.includes(value)) {
                    filters.push(value);
                    saveFiltersMessages(filters);
                }
            }
            input.value = '';
            renderFilters();
        }
        function removeFilter(type, filter) {
            if (type === 'user') {
                const filters = loadFiltersUsernames().filter(f => f !== filter);
                saveFiltersUsernames(filters);
            } else {
                const filters = loadFiltersMessages().filter(f => f !== filter);
                saveFiltersMessages(filters);
            }
            renderFilters();
        }
        // Collapsible filters UI for both username and message sections
        function initFilterCollapse() {
            try {
                const buttons = document.querySelectorAll('.toggle-btn[data-target]');
                buttons.forEach(btn => {
                    const targetId = btn.getAttribute('data-target');
                    const body = document.getElementById(targetId);
                    if (!body) return;
                    const key = `filters_collapsed_${targetId}`;
                    const collapsed = window.localStorage && localStorage.getItem(key) === '1';
                    if (collapsed) body.classList.add('collapsed');
                    const updateBtn = () => {
                        const isCollapsed = body.classList.contains('collapsed');
                        btn.textContent = isCollapsed ? 'Show' : 'Hide';
                        btn.setAttribute('aria-expanded', String(!isCollapsed));
                    };
                    btn.addEventListener('click', () => {
                        body.classList.toggle('collapsed');
                        try { if (window.localStorage) localStorage.setItem(key, body.classList.contains('collapsed') ? '1' : '0'); } catch (e) { }
                        updateBtn();
                    });
                    updateBtn();
                });
            } catch (e) { console.warn('Filter collapse init failed', e); }
        }
        // Import / Export helpers
        function tryLoadLegacyCombined() {
            try {
                let data = null;
                try { if (window.localStorage) data = localStorage.getItem('chat_filters'); } catch (e) { }
                if (!data) data = getCookie('chat_filters');
                if (!data) return null;
                return JSON.parse(data);
            } catch (e) { return null; }
        }
        function importFiltersFromObject(obj) {
            try {
                if (Array.isArray(obj)) {
                    // treat as message filters
                    saveFiltersMessages(obj);
                } else if (obj && typeof obj === 'object') {
                    if (Array.isArray(obj.usernames)) saveFiltersUsernames(obj.usernames);
                    if (Array.isArray(obj.messages)) saveFiltersMessages(obj.messages);
                }
                renderFilters();
                Toastify({ text: 'Filters imported', duration: 2500, gravity: 'bottom', position: 'right', style: { background: '#22c55e', color: '#fff' } }).showToast();
            } catch (e) { Toastify({ text: 'Import failed', duration: 2500, gravity: 'bottom', position: 'right', style: { background: '#ff4d4f', color: '#fff' } }).showToast(); }
        }
        async function exportFilters() {
            try {
                const users = loadFiltersUsernames();
                const msgs = loadFiltersMessages();
                const out = { usernames: users, messages: msgs };
                const txt = JSON.stringify(out, null, 2);
                await navigator.clipboard.writeText(txt);
                Toastify({
                    text: 'Filters copied to clipboard',
                    duration: 2500,
                    gravity: 'bottom',
                    position: 'right',
                    style: { background: '#22c55e', color: '#fff' }
                }).showToast();
            } catch (e) {
                Toastify({
                    text: 'Export failed',
                    duration: 2500,
                    gravity: 'bottom',
                    position: 'right',
                    style: { background: '#ff4d4f', color: '#fff' }
                }).showToast();
            }
        }
        // Wire import/export UI
        function initImportExportUI() {
            try {
                const exportBtn = document.getElementById('export-filters-btn');
                const openImportBtn = document.getElementById('open-import-filters-btn');
                const importPanel = document.getElementById('import-filters-panel-inline');
                const importBtn = document.getElementById('import-filters-btn');
                const cancelBtn = document.getElementById('cancel-import-filters-btn');
                if (exportBtn) exportBtn.addEventListener('click', exportFilters);
                if (openImportBtn && importPanel) openImportBtn.addEventListener('click', () => { importPanel.style.display = 'block'; });
                if (cancelBtn && importPanel) cancelBtn.addEventListener('click', () => { importPanel.style.display = 'none'; });
                if (importBtn && importPanel) importBtn.addEventListener('click', () => {
                    // Prefer user-provided JSON in the textarea. If empty, attempt legacy import.
                    const textarea = importPanel.querySelector('#import-filters-textarea');
                    const raw = textarea ? (textarea.value || '').trim() : '';
                    if (raw) {
                        try {
                            const parsed = JSON.parse(raw);
                            importFiltersFromObject(parsed);
                            importPanel.style.display = 'none';
                            // clear textarea after successful import
                            if (textarea) textarea.value = '';
                            return;
                        } catch (err) {
                            Toastify({ text: 'Invalid JSON: ' + (err.message || ''), duration: 3500, gravity: 'bottom', position: 'right', style: { background: '#ff4d4f', color: '#fff' } }).showToast();
                            return;
                        }
                    }
                    // No textarea content — try legacy storage locations
                    const legacy = tryLoadLegacyCombined();
                    if (legacy) {
                        importFiltersFromObject(legacy);
                        importPanel.style.display = 'none';
                    } else {
                        Toastify({ text: 'No legacy filters found', duration: 2500, gravity: 'bottom', position: 'right', style: { background: '#ff4d4f', color: '#fff' } }).showToast();
                    }
                });
            } catch (e) { console.warn('Import/Export init failed', e); }
        }
        function isMessageFiltered(event) {
            const messageText = (event.message?.text || '').toString().toLowerCase();
            const username = (event.chatter_user_login || '').toString().toLowerCase();
            const displayName = (event.chatter_user_name || event.chatter_user_display_name || '').toString().toLowerCase();
            // Check username filters (exact match) - only check actual Twitch username/displayName, not nicknames
            const userFilters = loadFiltersUsernames();
            if (userFilters.some(f => {
                const filterLower = f.toLowerCase();
                return filterLower === username || filterLower === displayName;
            })) {
                return true;
            }
            // Check message phrase filters (contains)
            const msgFilters = loadFiltersMessages();
            if (msgFilters.some(f => messageText.includes(f.toLowerCase()))) {
                return true;
            }
            return false;
        }
        // Token validation - fetch actual expires_in from Twitch API
        async function validateToken() {
            try {
                const response = await fetch('https://id.twitch.tv/oauth2/validate', {
                    method: 'GET',
                    headers: {
                        'Authorization': `OAuth ${accessToken}`
                    }
                });
                if (response.ok) {
                    const data = await response.json();
                    tokenExpiresIn = data.expires_in;
                    tokenValidatedAt = Math.floor(Date.now() / 1000);
                    tokenScopes = data.scopes || [];
                    console.log(`Token validated: expires in ${tokenExpiresIn}s (${Math.floor(tokenExpiresIn / 3600)}h ${Math.floor((tokenExpiresIn % 3600) / 60)}m)`);
                    // Check for required IRC scopes
                    const hasIRCRead = tokenScopes.includes('chat:read');
                    const hasIRCEdit = tokenScopes.includes('chat:edit');
                    if (!hasIRCRead || !hasIRCEdit) {
                        console.warn('⚠️ Token missing IRC chat scopes. Required: chat:read, chat:edit');
                        console.warn('⚠️ IRC chat connection will fail. Please re-authenticate with correct scopes.');
                        Toastify({
                            text: '⚠️ Your token is missing required chat scopes. IRC connection will fail.',
                            duration: 10000,
                            gravity: 'top',
                            position: 'right',
                            style: { background: 'linear-gradient(to right, #ff5f6d, #ffc371)' }
                        }).showToast();
                    }
                    return true;
                } else if (response.status === 401) {
                    console.error('Token validation failed: invalid token');
                    handleSessionExpiry();
                    return false;
                } else {
                    console.warn('Token validation returned unexpected status:', response.status);
                    return false;
                }
            } catch (error) {
                console.error('Error validating token:', error);
                return false;
            }
        }
        // Token management
        function updateTokenTimer() {
            let remaining;
            // If we have validated token data, use it for accurate countdown
            if (tokenExpiresIn !== null && tokenValidatedAt !== null) {
                const now = Math.floor(Date.now() / 1000);
                const elapsedSinceValidation = now - tokenValidatedAt;
                remaining = tokenExpiresIn - elapsedSinceValidation;
            } else {
                // Fallback to calculated time if validation hasn't happened yet
                const now = Math.floor(Date.now() / 1000);
                const elapsed = now - tokenCreatedAt;
                remaining = (4 * 60 * 60) - elapsed; // 4 hours in seconds
            }
            // Refresh token 5 minutes (300 seconds) before expiry to prevent message loss
            if (remaining <= 300 && remaining > 0 && !isRefreshing) {
                console.log(`Token refresh triggered with ${remaining}s remaining`);
                refreshToken();
                return;
            }
            if (remaining <= 0) {
                // Token expired without successful refresh
                document.getElementById('token-timer').textContent = 'Refreshing...';
                if (!isRefreshing) {
                    refreshToken();
                }
                return;
            }
            const hours = Math.floor(remaining / 3600);
            const minutes = Math.floor((remaining % 3600) / 60);
            const seconds = remaining % 60;
            document.getElementById('token-timer').textContent =
                `${hours}h ${minutes}m ${seconds}s`;
        }
        let isRefreshing = false;
        async function refreshToken() {
            if (isRefreshing) return;
            isRefreshing = true;
            isReconnecting = true;
            console.log('Refreshing access token...');
            updateStatus(false, 'Refreshing Token...');
            Toastify({
                text: 'Refreshing connection...',
                duration: 3000,
                gravity: 'top',
                position: 'right',
                backgroundColor: '#9147ff'
            }).showToast();
            try {
                const response = await fetch('?action=refresh_token');
                const data = await response.json();
                if (data.success) {
                    accessToken = data.access_token;
                    CONFIG.ACCESS_TOKEN = data.access_token; // Update CONFIG as well
                    tokenCreatedAt = data.created_at;
                    CONFIG.TOKEN_CREATED_AT = data.created_at;
                    console.log('Token refreshed successfully');
                    // Validate new token to get accurate expires_in
                    await validateToken();
                    // Seamless WebSocket handover: connect new before closing old
                    await seamlessWebSocketReconnect();
                } else {
                    console.error('Token refresh failed:', data.error);
                    handleSessionExpiry();
                }
            } catch (error) {
                console.error('Error refreshing token:', error);
                handleSessionExpiry();
            } finally {
                isRefreshing = false;
                isReconnecting = false;
                // Process any buffered messages
                processMessageBuffer();
            }
        }
        async function seamlessWebSocketReconnect() {
            console.log('Reconnecting WebSockets with new token...');
            // Close existing connections
            if (ircWs && ircWs.readyState === WebSocket.OPEN) {
                ircWs.close();
            }
            if (eventSubWs && eventSubWs.readyState === WebSocket.OPEN) {
                eventSubWs.close();
            }
            // Reset reconnection attempts
            ircReconnectAttempts = 0;
            eventSubReconnectAttempts = 0;
            // Wait a moment for clean disconnect
            await new Promise(resolve => setTimeout(resolve, 500));
            // Reconnect both WebSockets
            connectIRCWebSocket();
            connectEventSubWebSocket();
        }
        async function handleSessionExpiry() {
            console.log('Token refresh failed - ending session...');
            // Disconnect both WebSockets
            if (ircWs) {
                ircWs.close();
                ircWs = null;
            }
            if (eventSubWs) {
                eventSubWs.close();
                eventSubWs = null;
            }
            updateStatus(false, 'Session Expired');
            document.getElementById('token-timer').textContent = 'EXPIRED';
            // Destroy session on server
            try {
                await fetch('?action=expire_session');
            } catch (error) {
                console.error('Error expiring session:', error);
            }
            // Display expiry message
            const overlay = document.getElementById('chat-overlay');
            overlay.innerHTML = `
                <div class="expired-message">
                    <h3>Session Expired</h3>
                    <p>Unable to refresh your session. Please refresh the page to log in again.</p>
                    <p style="margin-top: 10px; font-size: 14px;">Refresh the page to start a new session.</p>
                </div>
            `;
        }
        // Logout action: destroy PHP session and reload to show login button
        async function logoutUser() {
            try {
                if (ircWs) {
                    ircWs.close();
                    ircWs = null;
                }
                if (eventSubWs) {
                    eventSubWs.close();
                    eventSubWs = null;
                }
                await fetch('?action=expire_session');
            } catch (err) {
                console.error('Logout error:', err);
            }
            // Reload to show login view
            window.location.reload();
        }
        // IRC Message Parsing Functions
        function parseIRCTags(tagString) {
            const tags = {}; if (!tagString) return tags;
            const tagPairs = tagString.split(';');
            for (const pair of tagPairs) {
                const [key, value] = pair.split('=');
                tags[key] = value ? decodeIRCValue(value) : '';
            }
            return tags;
        }
        function decodeIRCValue(value) {
            return value
                .replace(/\\s/g, ' ')
                .replace(/\\n/g, '\n')
                .replace(/\\r/g, '\r')
                .replace(/\\:/g, ';')
                .replace(/\\\\/g, '\\');
        }
        function parseIRCMessage(rawMessage) {
            let message = { raw: rawMessage, tags: {}, prefix: '', command: '', params: [] };
            let position = 0;
            // Parse tags if present (starts with @)
            if (rawMessage[0] === '@') {
                const tagsEnd = rawMessage.indexOf(' ');
                message.tags = parseIRCTags(rawMessage.substring(1, tagsEnd));
                position = tagsEnd + 1;
            }
            // Parse prefix if present (starts with :)
            if (rawMessage[position] === ':') {
                const prefixEnd = rawMessage.indexOf(' ', position);
                message.prefix = rawMessage.substring(position + 1, prefixEnd);
                position = prefixEnd + 1;
            }
            // Parse command
            const commandEnd = rawMessage.indexOf(' ', position);
            if (commandEnd === -1) {
                message.command = rawMessage.substring(position);
                return message;
            }
            message.command = rawMessage.substring(position, commandEnd);
            position = commandEnd + 1;
            // Parse parameters
            while (position < rawMessage.length) {
                if (rawMessage[position] === ':') {
                    message.params.push(rawMessage.substring(position + 1));
                    break;
                }
                const nextSpace = rawMessage.indexOf(' ', position);
                if (nextSpace === -1) {
                    message.params.push(rawMessage.substring(position));
                    break;
                }
                message.params.push(rawMessage.substring(position, nextSpace));
                position = nextSpace + 1;
            }
            return message;
        }
        function parseBadges(badgeString) {
            if (!badgeString) return [];
            const badges = [];
            const badgePairs = badgeString.split(',');
            for (const pair of badgePairs) {
                const [set_id, id] = pair.split('/');
                badges.push({ set_id, id, info: '' });
            }
            return badges;
        }
        function parseEmotes(text, emotesString) {
            if (!emotesString) {
                return [{ type: 'text', text: text }];
            }
            // Parse emotes format: emoteId:start-end,start-end/emoteId:start-end
            const emoteData = [];
            const emoteParts = emotesString.split('/');
            for (const part of emoteParts) {
                const [emoteId, positions] = part.split(':');
                const ranges = positions.split(',');
                for (const range of ranges) {
                    const [start, end] = range.split('-').map(Number);
                    emoteData.push({ emoteId, start, end });
                }
            }
            // Sort by position
            emoteData.sort((a, b) => a.start - b.start);
            // Build fragments
            const fragments = [];
            let lastEnd = 0;
            for (const emote of emoteData) {
                // Add text before emote
                if (emote.start > lastEnd) {
                    fragments.push({
                        type: 'text',
                        text: text.substring(lastEnd, emote.start)
                    });
                }
                // Add emote
                fragments.push({
                    type: 'emote',
                    text: text.substring(emote.start, emote.end + 1),
                    emote: {
                        id: emote.emoteId,
                        emote_set_id: ''
                    }
                });
                lastEnd = emote.end + 1;
            }
            // Add remaining text
            if (lastEnd < text.length) {
                fragments.push({
                    type: 'text',
                    text: text.substring(lastEnd)
                });
            }
            return fragments.length > 0 ? fragments : [{ type: 'text', text: text }];
        }
        // WebSocket management
        function updateStatus(connected, text) {
            const statusLight = document.getElementById('ws-status');
            const statusText = document.getElementById('ws-status-text');
            // Auto-detect connection status if not provided
            if (connected === undefined || connected === null) {
                // Check both connections
                const ircConnected = ircWs && ircWs.readyState === WebSocket.OPEN && ircJoined;
                const eventSubConnected = eventSubWs && eventSubWs.readyState === WebSocket.OPEN && eventSubConnected;
                connected = ircConnected && eventSubConnected;
                if (ircConnected && eventSubConnected) {
                    text = 'Connected';
                } else if (ircConnected && !eventSubConnected) {
                    text = 'IRC: Connected | EventSub: Connecting...';
                } else if (!ircConnected && eventSubConnected) {
                    text = 'IRC: Connecting... | EventSub: Connected';
                } else {
                    text = 'Connecting...';
                }
            }
            if (connected) {
                statusLight.classList.add('connected');
            } else {
                statusLight.classList.remove('connected');
            }
            statusText.textContent = text;
            // Also update the chat overlay placeholder text if present
            try {
                const overlay = document.getElementById('chat-overlay');
                if (overlay) {
                    const ph = overlay.querySelector('.chat-placeholder');
                    if (ph) ph.textContent = text;
                }
            } catch (e) {
                console.warn('Failed to update chat placeholder status text', e);
            }
        }
        function setupWebSocketHandlers(socket) {
            socket.onopen = () => {
                console.log('IRC WebSocket connected');
                ircAuthenticated = false;
                ircJoined = false;
                // Validate token before attempting IRC auth
                if (!CONFIG.ACCESS_TOKEN) {
                    console.error('No access token available for IRC authentication');
                    updateStatus(false, 'Error: No access token');
                    return;
                }
                // Send IRC CAP REQ for capabilities
                socket.send('CAP REQ :twitch.tv/tags twitch.tv/commands twitch.tv/membership');
                // Send PASS (OAuth token)
                socket.send(`PASS oauth:${CONFIG.ACCESS_TOKEN}`);
                // Send NICK (username)
                socket.send(`NICK ${CONFIG.USER_LOGIN.toLowerCase()}`);
                updateStatus(false, 'Authenticating...');
            };
            socket.onmessage = (event) => {
                // IRC messages are delimited by CRLF
                const messages = event.data.split('\r\n').filter(msg => msg.length > 0);
                
                for (const rawMessage of messages) {
                    const message = parseIRCMessage(rawMessage);
                    handleIRCMessage(message);
                }
            };
            socket.onerror = (error) => {
                console.error('IRC WebSocket error:', error);
                if (!connectionErrorToast) {
                    connectionErrorToast = Toastify({
                        text: 'Connection error. Reconnecting...',
                        duration: -1,
                        gravity: 'top',
                        position: 'right',
                        style: { background: 'linear-gradient(to right, #ff5f6d, #ffc371)' }
                    }).showToast();
                }
                updateStatus(false, 'Error');
            };
            socket.onclose = () => {
                console.log('IRC WebSocket closed');
                ircAuthenticated = false;
                ircJoined = false;
                updateStatus(false, 'IRC: Disconnected');
                if (pingTimeoutHandle) {
                    clearTimeout(pingTimeoutHandle);
                    pingTimeoutHandle = null;
                }
                // Clear presence tracking
                activeChatters.clear();
                messageBasedChatters.clear();
                // Attempt reconnection
                if (ircReconnectAttempts < maxReconnectAttempts) {
                    const delay = Math.min(1000 * Math.pow(2, ircReconnectAttempts), 30000);
                    ircReconnectAttempts++;
                    console.log(`IRC Reconnecting in ${delay}ms (attempt ${ircReconnectAttempts}/${maxReconnectAttempts})`);
                    setTimeout(connectIRCWebSocket, delay);
                } else {
                    console.error('IRC: Failed to connect after multiple attempts');
                }
            };
        }
        function connectIRCWebSocket() {
            if (ircWs && ircWs.readyState === WebSocket.OPEN) {
                ircWs.close();
            }
            
            // Check for required IRC scopes
            const hasIRCRead = tokenScopes.includes('chat:read');
            const hasIRCEdit = tokenScopes.includes('chat:edit');
            
            if (!hasIRCRead || !hasIRCEdit) {
                console.error('❌ Cannot connect to IRC: Missing required scopes (chat:read, chat:edit)');
                updateStatus(false, 'IRC: Missing Scopes');
                
                Toastify({
                    text: 'Cannot connect to IRC chat: Your token is missing required scopes. Please log out and re-authenticate.',
                    duration: -1,
                    gravity: 'top',
                    position: 'right',
                    style: { background: 'linear-gradient(to right, #ff5f6d, #ffc371)' }
                }).showToast();
                
                return;
            }
            
            console.log('Connecting to IRC WebSocket...');
            ircWs = new WebSocket(CONFIG.IRC_WS_URL);
            setupWebSocketHandlers(ircWs);
        }
        // Setup PING timeout for IRC
        function setupPingTimeout() {
            if (pingTimeoutHandle) {
                clearTimeout(pingTimeoutHandle);
            }
            // If we don't receive a PING within timeout period, connection is dead
            pingTimeoutHandle = setTimeout(() => {
                console.warn('No PING received within timeout, reconnecting...');
                if (ircWs) {
                    ircWs.close();
                }
            }, pingTimeoutSeconds * 1000);
        }
        // EventSub WebSocket Handlers
        function setupEventSubWebSocketHandlers(socket) {
            socket.onopen = () => {
                console.log('EventSub WebSocket connected');
            };
            socket.onmessage = async (event) => {
                const message = JSON.parse(event.data);
                // Update keepalive timeout
                if (message.payload?.session?.keepalive_timeout_seconds) {
                    keepaliveTimeoutSeconds = message.payload.session.keepalive_timeout_seconds;
                }
                setupKeepaliveTimeout(keepaliveTimeoutSeconds);
                await handleEventSubMessage(message);
            };
            socket.onerror = (error) => {
                console.error('EventSub WebSocket error:', error);
            };
            socket.onclose = () => {
                console.log('EventSub WebSocket closed');
                eventSubConnected = false;
                eventSubSessionId = null;
                if (keepaliveTimeoutHandle) {
                    clearTimeout(keepaliveTimeoutHandle);
                    keepaliveTimeoutHandle = null;
                }
                // Attempt reconnection
                if (eventSubReconnectAttempts < maxReconnectAttempts) {
                    const delay = Math.min(1000 * Math.pow(2, eventSubReconnectAttempts), 30000);
                    eventSubReconnectAttempts++;
                    console.log(`EventSub Reconnecting in ${delay}ms (attempt ${eventSubReconnectAttempts}/${maxReconnectAttempts})`);
                    setTimeout(connectEventSubWebSocket, delay);
                } else {
                    console.error('EventSub: Failed to connect after multiple attempts');
                }
            };
        }
        function connectEventSubWebSocket() {
            if (eventSubWs && eventSubWs.readyState === WebSocket.OPEN) {
                eventSubWs.close();
            }
            console.log('Connecting to EventSub WebSocket...');
            eventSubWs = new WebSocket(CONFIG.EVENTSUB_WS_URL);
            setupEventSubWebSocketHandlers(eventSubWs);
        }
        // Subscribe to activity events (NOT chat messages)
        async function subscribeToActivityEvents() {
            if (!eventSubSessionId) {
                console.error('Cannot subscribe: No EventSub session ID');
                return;
            }
            
            const broadcasterUserId = CONFIG.USER_ID;
            
            // List of activity event subscriptions (excluding chat messages)
            const subscriptions = [
                {
                    type: 'channel.channel_points_automatic_reward_redemption.add',
                    version: '1',
                    condition: { broadcaster_user_id: broadcasterUserId }
                },
                {
                    type: 'channel.channel_points_custom_reward_redemption.add',
                    version: '1',
                    condition: { broadcaster_user_id: broadcasterUserId }
                },
                {
                    type: 'channel.raid',
                    version: '1',
                    condition: { to_broadcaster_user_id: broadcasterUserId }
                },
                {
                    type: 'channel.cheer',
                    version: '1',
                    condition: { broadcaster_user_id: broadcasterUserId }
                },
                {
                    type: 'channel.chat.notification',
                    version: '1',
                    condition: {
                        broadcaster_user_id: broadcasterUserId,
                        user_id: broadcasterUserId
                    }
                }
            ];
            
            // Subscribe to each event type
            for (const subscription of subscriptions) {
                try {
                    const response = await fetch('https://api.twitch.tv/helix/eventsub/subscriptions', {
                        method: 'POST',
                        headers: {
                            'Authorization': `Bearer ${CONFIG.ACCESS_TOKEN}`,
                            'Client-Id': CONFIG.CLIENT_ID,
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            type: subscription.type,
                            version: subscription.version,
                            condition: subscription.condition,
                            transport: {
                                method: 'websocket',
                                session_id: eventSubSessionId
                            }
                        })
                    });
                    
                    if (response.ok) {
                        console.log(`✓ Subscribed to ${subscription.type}`);
                    } else {
                        const errorData = await response.json();
                        console.error(`✗ Failed to subscribe to ${subscription.type}:`, errorData);
                    }
                } catch (error) {
                    console.error(`Error subscribing to ${subscription.type}:`, error);
                }
            }
        }
        
        // Setup keepalive timeout for EventSub
        function setupKeepaliveTimeout(seconds) {
            if (keepaliveTimeoutHandle) {
                clearTimeout(keepaliveTimeoutHandle);
            }
            const timeoutDuration = (seconds + 3) * 1000;
            keepaliveTimeoutHandle = setTimeout(() => {
                console.error('EventSub keepalive timeout - no message received');
                if (eventSubWs) {
                    eventSubWs.close();
                }
            }, timeoutDuration);
        }
        // Handle EventSub messages
        async function handleEventSubMessage(message) {
            const metadata = message.metadata;
            const payload = message.payload;
            if (!metadata || !metadata.message_type) {
                return;
            }
            switch (metadata.message_type) {
                case 'session_welcome': {
                    eventSubSessionId = payload.session.id;
                    console.log('EventSub Session ID:', eventSubSessionId);
                    eventSubConnected = true;
                    eventSubReconnectAttempts = 0;
                    // Subscribe to activity events (NOT chat messages)
                    await subscribeToActivityEvents();
                    // Save session ID to server
                    await saveSessionIdToServer(eventSubSessionId);
                    // Update status if IRC is also connected
                    if (ircJoined) {
                        updateStatus(true, 'Connected');
                        if (connectionErrorToast) {
                            connectionErrorToast.hideToast();
                            connectionErrorToast = null;
                        }
                    }
                    break;
                }
                case 'session_keepalive':
                    // Just a keepalive, no action needed
                    break;
                case 'notification': {
                    const eventType = payload.subscription.type;
                    const eventData = payload.event;
                    // Handle activity events
                    if (eventType === 'channel.channel_points_automatic_reward_redemption.add') {
                        handleAutomaticReward(eventData);
                    } else if (eventType === 'channel.channel_points_custom_reward_redemption.add') {
                        handleCustomReward(eventData);
                    } else if (eventType === 'channel.raid') {
                        handleRaidEvent(eventData);
                    } else if (eventType === 'channel.cheer') {
                        handleBitsEvent(eventData);
                    } else if (eventType === 'channel.chat.notification') {
                        handleChatNotification(eventData);
                    }
                    break;
                }
                case 'session_reconnect': {
                    console.log('EventSub reconnecting to new session...');
                    const reconnectUrl = payload.session.reconnect_url;
                    if (eventSubWs) {
                        eventSubWs.close();
                    }
                    eventSubWs = new WebSocket(reconnectUrl);
                    setupEventSubWebSocketHandlers(eventSubWs);
                    break;
                }
                case 'revocation':
                    console.error('EventSub subscription revoked:', payload);
                    break;
            }
        }
        // Handle IRC messages
        function handleIRCMessage(message) {
            switch (message.command) {
                case 'PING':
                    // Respond to PING with PONG
                    if (ircWs && ircWs.readyState === WebSocket.OPEN) {
                        ircWs.send(`PONG :${message.params[0]}`);
                    }
                    setupPingTimeout();
                    break;
                case '001': // Welcome message
                    console.log('IRC authenticated successfully');
                    ircAuthenticated = true;
                    // Join our own channel
                    if (ircWs && ircWs.readyState === WebSocket.OPEN) {
                        ircWs.send(`JOIN #${CONFIG.USER_LOGIN.toLowerCase()}`);
                    }
                    break;
                case '002':
                case '003':
                case '004':
                case '375':
                case '372':
                case '376':
                case 'GLOBALUSERSTATE':
                    // Authentication messages, just log them
                    break;
                case 'CAP':
                    // Capability acknowledgment
                    if (message.params[1] === 'ACK') {}
                    break;
                case 'JOIN': {
                    // Successfully joined channel
                    const joinChannel = message.params[0];
                    const joinUser = message.prefix.split('!')[0];
                    if (joinUser.toLowerCase() === CONFIG.USER_LOGIN.toLowerCase()) {
                        console.log('Successfully joined channel:', joinChannel);
                        ircJoined = true;
                        ircReconnectAttempts = 0;
                        // Update status based on both connections
                        if (eventSubConnected) {
                            updateStatus(true, 'Connected');
                            if (connectionErrorToast) {
                                connectionErrorToast.hideToast();
                                connectionErrorToast = null;
                            }
                        } else {
                            updateStatus(false, 'IRC: Connected | EventSub: Connecting...');
                        }
                        clearPlaceholderOnly();
                        // Fetch badges for the channel
                        fetchBadges();
                        // Setup ping timeout
                        setupPingTimeout();
                    } else {
                        // Another user joined
                        const userId = message.tags && message.tags['user-id'];
                        const displayName = (message.tags && message.tags['display-name']) || joinUser;
                        const userKey = userId || joinUser.toLowerCase(); // Use userId if available, otherwise login name
                        activeChatters.add(userKey);
                        // Show join message if presence notifications enabled and user hasn't sent a message yet
                        if (presenceEnabled && !messageBasedChatters.has(userKey)) {
                            showSystemMessage(`${displayName} joined the chat`, 'join');
                        }
                    }
                    break;
                }
                case '353': // NAMES list
                case '366': // End of NAMES list
                   // Membership information, we can ignore for now
                    break;
                case 'PART': {
                    // User left channel
                    const partUserId = message.tags && message.tags['user-id'];
                    const partUser = message.prefix.split('!')[0];
                    const partDisplayName = (message.tags && message.tags['display-name']) || partUser;
                    const partUserKey = partUserId || partUser.toLowerCase(); // Use userId if available, otherwise login name
                    
                    activeChatters.delete(partUserKey);
                    messageBasedChatters.delete(partUserKey);
                    
                    // Show leave message if presence notifications enabled
                    if (presenceEnabled) {
                        showSystemMessage(`${partDisplayName} left the chat`, 'leave');
                    }
                    break;
                }
                case 'PRIVMSG':
                    // Chat message
                    handleIRCChatMessage(message);
                    break;
                case 'USERSTATE':
                    // Our message was sent (echo)
                    console.log('Message sent successfully');
                    break;
                case 'ROOMSTATE':
                    // Room state update
                    console.log('Room state:', message.tags);
                    break;
                case 'USERNOTICE':
                    // Sub, resub, gift, raid, etc.
                    handleIRCUserNotice(message);
                    break;
                case 'CLEARCHAT': {
                    // Chat cleared or user timed out/banned
                    const overlay = document.getElementById('chat-overlay');
                    if (message.params.length > 1) {
                        const username = message.params[1];
                        const duration = message.tags['ban-duration'];
                        if (duration) {
                            showSystemMessage(`${username} was timed out for ${duration} seconds`, 'timeout');
                        } else {
                            showSystemMessage(`${username} was banned`, 'ban');
                        }
                        // Remove all messages from that user in the overlay and purge caches
                        try {
                            const normalized = username ? username.replace(':','').trim().toLowerCase() : null;
                            if (normalized && overlay) {
                                const msgs = overlay.querySelectorAll('.chat-message, .reward-message');
                                msgs.forEach(m => {
                                    const uname = m.querySelector('.chat-username')?.textContent?.replace(':','').trim().toLowerCase();
                                    if (uname === normalized) m.remove();
                                });
                                // Purge from recent chat cache
                                recentChatMessages = recentChatMessages.filter(e => {
                                    const login = (e.user_login || e.user_name || '').toLowerCase();
                                    return login !== normalized && (e.user_name || '').toLowerCase() !== normalized;
                                });
                                // Remove from presence sets
                                messageBasedChatters.delete(normalized);
                                activeChatters.delete(normalized);
                                // If target user-id is present, remove by id too
                                if (message.tags && message.tags['target-user-id']) {
                                    const targetId = message.tags['target-user-id'];
                                    messageBasedChatters.delete(targetId);
                                    activeChatters.delete(targetId);
                                    recentChatMessages = recentChatMessages.filter(e => (e.user_id || '') !== targetId);
                                }
                                // Persist updated history
                                saveChatHistory();
                            }
                        } catch (e) {
                            console.error('Error handling CLEARCHAT for user:', e);
                        }
                    } else {
                        // Full chat clear: clear UI, server history and reset local caches
                        try {
                            clearChatHistory(); // clears DOM and server-side persisted chat
                            recentChatMessages = [];
                            recentRedemptions = [];
                            recentBitsEvents = [];
                            activeChatters.clear();
                            messageBasedChatters.clear();
                            // Remove presence summary if present
                            if (overlay) {
                                const presenceEl = overlay.querySelector('.system-message.join');
                                if (presenceEl) presenceEl.remove();
                            }
                        } catch (e) {
                            console.error('Error handling CLEARCHAT (full clear):', e);
                        }
                    }
                    break;
                }
                case 'CLEARMSG': {
                    // Single message deleted
                    const targetMsgId = message.tags['target-msg-id'];
                    if (targetMsgId) {
                        handleMessageDelete({ message_id: targetMsgId });
                    }
                    break;
                }
                case 'NOTICE': {
                    // Server notice
                    const noticeMsg = message.params[message.params.length - 1] || '';
                    const msgId = message.tags['msg-id'];
                    console.warn('🔔 IRC NOTICE:', msgId, noticeMsg);
                    
                    // Handle authentication failure specifically
                    if (noticeMsg.includes('Login unsuccessful') || noticeMsg.includes('Login authentication failed')) {
                        console.error('❌ IRC authentication failed');
                        console.error('Token scopes:', tokenScopes.join(', '));
                        const hasIRCRead = tokenScopes.includes('chat:read');
                        const hasIRCEdit = tokenScopes.includes('chat:edit');
                        let errorMessage = 'IRC authentication failed. ';
                        if (!hasIRCRead || !hasIRCEdit) {
                            errorMessage += `Missing required scopes: ${!hasIRCRead ? 'chat:read ' : ''}${!hasIRCEdit ? 'chat:edit' : ''}. Please log out and re-authenticate.`;
                        } else {
                            errorMessage += 'Your token may be invalid. Please log out and re-authenticate.';
                        }
                        Toastify({
                            text: errorMessage,
                            duration: -1,
                            gravity: 'top',
                            position: 'right',
                            style: { background: 'linear-gradient(to right, #ff5f6d, #ffc371)' },
                            onClick: () => {
                                if (confirm('Would you like to log out and re-authenticate now?')) {
                                    logoutUser();
                                }
                            }
                        }).showToast();
                        updateStatus(false, 'IRC: Auth Failed');
                        // Stop reconnection attempts for auth failures
                        ircReconnectAttempts = maxReconnectAttempts;
                        return;
                    }
                    // Handle critical errors that should show to user
                    const criticalErrors = [
                        'msg_channel_suspended', 'msg_banned', 'msg_channel_blocked',
                        'msg_requires_verified_phone_number', 'msg_verified_email',
                        'tos_ban'
                    ];
                    if (criticalErrors.includes(msgId)) {
                        Toastify({
                            text: noticeMsg || 'Cannot connect to channel',
                            duration: -1,
                            gravity: 'top',
                            position: 'right',
                            style: { background: 'linear-gradient(to right, #ff5f6d, #ffc371)' }
                        }).showToast();
                    }
                    // Handle rate limit warnings
                    const rateLimitErrors = ['msg_ratelimit', 'msg_slowmode', 'msg_timedout'];
                    if (rateLimitErrors.includes(msgId)) {
                        Toastify({
                            text: noticeMsg,
                            duration: 5000,
                            gravity: 'top',
                            position: 'right',
                            style: { background: 'linear-gradient(to right, #ff9800, #ff5722)' }
                        }).showToast();
                    }
                    // Handle room mode changes (show as system messages)
                    const roomModeChanges = [
                        'emote_only_on', 'emote_only_off', 'followers_on', 'followers_off',
                        'slow_on', 'slow_off', 'subs_on', 'subs_off'
                    ];
                    if (roomModeChanges.includes(msgId)) {
                        showSystemMessage(noticeMsg, 'info');
                    }
                    break;
                }
                case 'RECONNECT':
                    // Server requests reconnection
                    console.log('Server requested reconnection');
                    if (ircWs) {
                        ircWs.close();
                    }
                    break;
                default:
                    console.log('Unhandled IRC command:', message.command, message);
            }
        }
        // Handle IRC PRIVMSG (chat messages)
        function handleIRCChatMessage(message) {
            const tags = message.tags;
            let text = message.params[message.params.length - 1] || '';
            // Handle ACTION messages (/me command)
            // IRC sends these as: \u0001ACTION message text\u0001
            if (text.startsWith('\u0001ACTION ')) {
                text = text.slice(8); // Remove '\u0001ACTION '
                if (text.endsWith('\u0001')) {
                    text = text.slice(0, -1); // Remove trailing '\u0001'
                }
            }
            // Extract user info from tags
            const userId = tags['user-id'];
            const username = message.prefix.split('!')[0];
            const displayName = tags['display-name'] || username;
            const color = tags['color'] || '#808080';
            const badges = tags['badges'] || '';
            const emotes = tags['emotes'] || '';
            const messageId = tags['id'];
            // Build event object matching EventSub format for compatibility
            const event = {
                chatter_user_id: userId,
                chatter_user_login: username,
                chatter_user_name: displayName,
                color: color,
                badges: parseBadges(badges),
                message: {
                    text: text,
                    fragments: parseEmotes(text, emotes)
                },
                message_id: messageId,
                message_type: 'text'
            };
            // Check for bits in message (Cheer emotes)
            const bits = tags['bits']; if (bits) {
                event.cheer = { bits: parseInt(bits) };
            }
            // Check for reply
            const replyParentMsgId = tags['reply-parent-msg-id'];
            if (replyParentMsgId) {
                event.reply = {
                    parent_message_id: replyParentMsgId,
                    parent_user_login: tags['reply-parent-user-login'] || '',
                    parent_message_body: tags['reply-parent-msg-body'] || ''
                };
            }
            // Track presence
            const userKey = userId || username.toLowerCase(); // Use userId if available, otherwise login name
            activeChatters.add(userKey);
            messageBasedChatters.add(userKey);
            
            // Apply filters
            if (isMessageFiltered(event)) {
                return;
            }
            // Check for duplicates with redemptions/bits
            const messageText = event.message.text;
            if (consumeMatchingRedemption(event.chatter_user_login, event.chatter_user_name, event.chatter_user_id, messageText)) {
                return;
            }
            if (consumeMatchingBitsEvent(event.chatter_user_login, event.chatter_user_name, event.chatter_user_id, messageText)) {
                return;
            }
            // Add to recent messages cache
            addRecentChatMessage(event.chatter_user_login, event.chatter_user_name, event.chatter_user_id, messageText);
            // Display message using existing handler
            handleChatMessage(event);
        }
        // Handle IRC USERNOTICE (subs, resubs, gifts, raids, etc.)
        function handleIRCUserNotice(message) {
            const tags = message.tags;
            const msgId = tags['msg-id'];
            const systemMsg = tags['system-msg'] || '';
            const userId = tags['user-id'];
            const username = tags['login'] || '';
            const displayName = tags['display-name'] || username;
            const messageText = message.params[message.params.length - 1] || '';
            // Build notification event
            let noticeType = 'unknown';
            let noticeHtml = '';
            switch (msgId) {
                case 'sub':
                case 'resub':
                    noticeType = 'subscription';
                    const months = tags['msg-param-cumulative-months'] || '1';
                    noticeHtml = `🎉 ${escapeHtml(displayName)} subscribed${months > 1 ? ` for ${months} months` : ''}!`;
                    if (messageText) {
                        noticeHtml += `<br><em>${escapeHtml(messageText)}</em>`;
                    }
                    break;
                case 'subgift':
                case 'anonsubgift':
                    noticeType = 'gift';
                    const recipient = tags['msg-param-recipient-display-name'] || '';
                    const gifter = msgId === 'anonsubgift' ? 'An anonymous user' : escapeHtml(displayName);
                    noticeHtml = `🎁 ${gifter} gifted a subscription to ${escapeHtml(recipient)}!`;
                    break;
                case 'submysterygift':
                case 'anonsubmysterygift':
                    noticeType = 'gift';
                    const giftCount = tags['msg-param-mass-gift-count'] || '1';
                    const mysteryGifter = msgId === 'anonsubmysterygift' ? 'An anonymous user' : escapeHtml(displayName);
                    noticeHtml = `🎁 ${mysteryGifter} gifted ${giftCount} subscriptions!`;
                    break;
                case 'raid':
                    noticeType = 'raid';
                    const viewers = tags['msg-param-viewerCount'] || '0';
                    noticeHtml = `⚔️ ${escapeHtml(displayName)} is raiding with ${viewers} viewers!`;
                    break;
                default:
                    noticeType = 'notification';
                    noticeHtml = escapeHtml(systemMsg.replace(/\\s/g, ' '));
            }
            // Display notification
            const overlay = document.getElementById('chat-overlay');
            const noticeElement = document.createElement('div');
            noticeElement.className = `chat-notification chat-notification-${noticeType}`;
            noticeElement.innerHTML = noticeHtml;
            overlay.appendChild(noticeElement);
            enforceMessageCap();
            // Scroll to bottom
            overlay.scrollTop = overlay.scrollHeight;
            // Add to activity feed
            const activityFeed = document.getElementById('activity-feed-scroll');
            if (activityFeed) {
                const activityElement = document.createElement('div');
                activityElement.className = `activity-item activity-${noticeType}`;
                activityElement.innerHTML = `
                    <span class="activity-time">${new Date().toLocaleTimeString()}</span>
                    <span class="activity-text">${noticeHtml}</span>
                `;
                activityFeed.insertBefore(activityElement, activityFeed.firstChild);
            }
        }
        // Log raw chat event data to server for debugging
        async function logRawChatData(event) {
            try {
                const response = await fetch('?action=log_raw_chat', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(event)
                });
                const result = await response.json();
                if (!result.success) {
                    console.error('Failed to log raw chat data:', result.error);
                }
            } catch (error) {
                console.error('Error logging raw chat data:', error);
            }
        }
        // Chat message handling
        function handleChatMessage(event) {
            // Log raw event data for debugging streaks and other features
            logRawChatData(event);
            // Check if message should be filtered
            if (isMessageFiltered(event)) {
                return;
            }
            // Check if this user needs to be marked as joined first
            const userLogin = event.chatter_user_login;
            const userKey = event.chatter_user_id || (userLogin ? userLogin.toLowerCase() : null);
            if (presenceEnabled && userKey && !activeChatters.has(userKey) && !messageBasedChatters.has(userKey)) {
                // User hasn't been detected by presence system, mark them as joined via message
                messageBasedChatters.add(userKey);
                showSystemMessage(`${event.chatter_user_name} joined the chat`, 'join');
            }
            // Presence is handled via Twitch Helix API (no message-based presence)
            // Deduplicate: if a recent redemption from same user with identical text exists, skip showing this chat message
            const chatTextForMatch = extractTextFromEvent(event) || (event.message && event.message.text) || '';
            try {
                if (consumeMatchingRedemption(
                    event.chatter_user_login || null,
                    event.chatter_user_name || event.chatter_user_display_name || null,
                    event.chatter_user_id || null,
                    chatTextForMatch
                )) {
                    console.log('Suppressed chat message because a matching recent redemption was recorded:', chatTextForMatch);
                    return;
                }
            } catch (e) {
                console.error('Error checking recent redemptions cache', e);
            }
            // Deduplicate: if a recent bits event from same user with identical message exists, skip showing this chat message
            try {
                if (consumeMatchingBitsEvent(
                    event.chatter_user_login || null,
                    event.chatter_user_name || event.chatter_user_display_name || null,
                    event.chatter_user_id || null,
                    chatTextForMatch
                )) {
                    console.log('Suppressed chat message because a matching recent bits event was recorded:', chatTextForMatch);
                    return;
                }
            } catch (e) {
                console.error('Error checking recent bits events cache', e);
            }
            // Cache this chat message for bidirectional deduplication (in case redemption arrives after)
            try {
                addRecentChatMessage(
                    event.chatter_user_login || null,
                    event.chatter_user_name || event.chatter_user_display_name || null,
                    event.chatter_user_id || null,
                    chatTextForMatch
                );
            } catch (e) {
                console.error('Error caching chat message', e);
            }
            const overlay = document.getElementById('chat-overlay');
            // Clear placeholder text
            if (overlay.children.length === 1 && overlay.children[0].tagName === 'P') {
                clearPlaceholderOnly();
            }
            const messageDiv = document.createElement('div');
            messageDiv.className = 'chat-message';
            messageDiv.setAttribute('data-message-id', event.message_id);
            // Add special classes based on message_type
            if (event.message_type) {
                messageDiv.setAttribute('data-message-type', event.message_type);
                if (event.message_type === 'user_intro' || event.is_first_message) {
                    messageDiv.classList.add('first-time-chatter');
                } else if (event.message_type === 'action') {
                    messageDiv.classList.add('action-message');
                } else if (event.message_type === 'channel_points_highlighted') {
                    messageDiv.classList.add('highlighted-message');
                } else if (event.message_type === 'channel_points_sub_only') {
                    messageDiv.classList.add('sub-only-message');
                } else if (event.message_type === 'power_ups_message_effect') {
                    messageDiv.classList.add('power-ups-effect');
                } else if (event.message_type === 'power_ups_gigantified_emote') {
                    messageDiv.classList.add('gigantified-emote');
                }
            }
            // Format timestamp from event created_at with fallback to current time
            let messageDate;
            if (event.created_at) {
                messageDate = new Date(event.created_at);
                // Check if date is valid
                if (isNaN(messageDate.getTime())) {
                    messageDate = new Date();
                }
            } else {
                messageDate = new Date();
            }
            const hours = messageDate.getHours().toString().padStart(2, '0');
            const minutes = messageDate.getMinutes().toString().padStart(2, '0');
            const seconds = messageDate.getSeconds().toString().padStart(2, '0');
            const timestamp = `${hours}:${minutes}:${seconds}`;
            let messageHtml = `<span class="chat-timestamp">${timestamp}</span>`;
            // Reply context (if this is a reply to another message)
            if (event.reply) {
                const replyUsername = (userSettings?.nicknames && userSettings.nicknames[event.reply.parent_user_login?.toLowerCase()]) || event.reply.parent_user_name || event.reply.parent_user_login;
                const replyBody = escapeHtml(event.reply.parent_message_body || '(message)');
                messageHtml += `
                                    <div class="reply-context">
                                        <span class="reply-icon">↩️</span>
                                        <span class="reply-text">Replying to ${escapeHtml(replyUsername)}: ${replyBody}</span>
                                    </div>
                                `;
            }
            // First-time chatter indicator
            if (event.message_type === 'user_intro') {
                messageHtml += `<div class="user-intro-badge">🎉 First-Time Chatter!</div>`;
            }
            // Build badges HTML
            // Use source_badges if in shared chat and available, otherwise use regular badges
            const badgesToDisplay = (event.source_badges && event.source_broadcaster_user_login) ? event.source_badges : event.badges;
            let badgesHtml = '';
            if (badgesToDisplay && badgesToDisplay.length > 0) {
                badgesHtml = '<span class="chat-badges">';
                badgesToDisplay.forEach(badge => {
                    // Look up badge URL from cache
                    const badgeUrl = badgeCache[badge.set_id]?.[badge.id];
                    if (badgeUrl) {
                        const badgeTitle = badge.info || badge.set_id;
                        badgesHtml += `<img class="chat-badge" src="${badgeUrl}" alt="${badge.set_id}" title="${badgeTitle}">`;
                    } else {
                        // Diagnostic to help track down missing badge sets/versions
                        console.debug('Badge image missing in badgeCache', badge.set_id, badge.id);
                        // Show a small placeholder so the UI layout isn't affected
                        badgesHtml += `<span class="chat-badge placeholder-badge" title="${badge.set_id}/${badge.id}"></span>`;
                    }
                });
                badgesHtml += '</span>';
            }
            // Check for nickname
            const userId = event.chatter_user_id;
            const nickname = getNickname(userId);
            const displayName = nickname || event.chatter_user_name;
            // Build message text HTML with full fragment support
            let messageTextHtml = '';
            let skippedReplyMention = false;
            if (event.message && event.message.fragments) {
                event.message.fragments.forEach((fragment, index) => {
                    if (fragment.type === 'text') {
                        let textContent = fragment.text;
                        // If we just skipped a reply mention, trim leading space from this text fragment
                        if (skippedReplyMention && textContent.startsWith(' ')) {
                            textContent = textContent.substring(1);
                            skippedReplyMention = false;
                        }
                        messageTextHtml += escapeHtml(textContent);
                    } else if (fragment.type === 'emote' && fragment.emote) {
                        const emoteClass = event.message_type === 'power_ups_gigantified_emote' ? 'chat-emote gigantified' : 'chat-emote';
                        messageTextHtml += `<img class="${emoteClass}" src="https://static-cdn.jtvnw.net/emoticons/v2/${fragment.emote.id}/default/dark/1.0" alt="${escapeHtml(fragment.text)}" title="${escapeHtml(fragment.text)}" style="vertical-align: middle; height: 1.5em;">`;
                    } else if (fragment.type === 'cheermote' && fragment.cheermote) {
                        const cheermoteColor = getCheermoteColor(fragment.cheermote.tier || 1);
                        const cheermoteText = `${fragment.cheermote.prefix}${fragment.cheermote.bits}`;
                        messageTextHtml += `<span class="cheermote" data-tier="${fragment.cheermote.tier || 1}" style="color: ${cheermoteColor};" title="${fragment.cheermote.bits} Bits">${escapeHtml(cheermoteText)}</span>`;
                    } else if (fragment.type === 'mention' && fragment.mention) {
                        // Skip the mention if this is a reply and the mention matches the parent user
                        if (event.reply && fragment.mention.user_login &&
                            fragment.mention.user_login.toLowerCase() === event.reply.parent_user_login?.toLowerCase()) {
                            // Skip this mention fragment - it's the "@username" reply indicator
                            skippedReplyMention = true;
                            return;
                        }
                        messageTextHtml += `<span class="mention" data-user-id="${fragment.mention.user_id}" title="@${escapeHtml(fragment.mention.user_login)}">${escapeHtml(fragment.text)}</span>`;
                    } else {
                        // Fallback for any other fragment type
                        messageTextHtml += escapeHtml(fragment.text || '');
                    }
                });
            } else {
                messageTextHtml = escapeHtml(event.message?.text || '');
            }
            // Check if it's a shared chat message
            const isSharedChat = event.source_broadcaster_user_id !== null && event.source_broadcaster_user_login && event.source_broadcaster_user_login.toLowerCase() !== CONFIG.USER_LOGIN.toLowerCase();
            const sharedChatIndicator = isSharedChat ?
                `<span class="shared-chat-indicator">[from ${escapeHtml(event.source_broadcaster_user_name || event.source_broadcaster_user_login)}]</span>` : '';
            // Adjust color for better readability against purple background
            const readableColor = adjustColorForReadability(event.color);
            // Handle action messages (/me) differently
            if (event.message_type === 'action') {
                messageHtml += `
                        ${badgesHtml}
                        ${sharedChatIndicator}
                        <span class="action-text" style="color: ${readableColor}"><em>${escapeHtml(displayName)} ${messageTextHtml}</em></span>
                    `;
            } else {
                messageHtml += `
                        ${badgesHtml}
                        <span class="chat-username" style="color: ${readableColor}">${escapeHtml(displayName)}:</span>
                        ${sharedChatIndicator}
                        <span class="chat-text">${messageTextHtml}</span>
                    `;
            }
            messageDiv.innerHTML = messageHtml;
            overlay.appendChild(messageDiv);
            // Auto-scroll to bottom
            overlay.scrollTop = overlay.scrollHeight;
            // Enforce cap (remove oldest messages if needed)
            enforceMessageCap(50);
            // Save chat history
            saveChatHistory();
        }
        // Adjust color for better readability against purple background
        function adjustColorForReadability(color) {
            // Default to white if no color provided
            if (!color || color === '' || color === '#000000') {
                return '#ffffff';
            }
            // Parse hex color to RGB
            let r, g, b;
            const hex = color.replace('#', '');
            if (hex.length === 3) {
                r = parseInt(hex[0] + hex[0], 16);
                g = parseInt(hex[1] + hex[1], 16);
                b = parseInt(hex[2] + hex[2], 16);
            } else if (hex.length === 6) {
                r = parseInt(hex.substring(0, 2), 16);
                g = parseInt(hex.substring(2, 4), 16);
                b = parseInt(hex.substring(4, 6), 16);
            } else {
                return '#ffffff'; // Invalid format, return white
            }
            // Calculate relative luminance (perceived brightness)
            const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
            // If color is too dark (luminance < 0.4), lighten it significantly
            if (luminance < 0.4) {
                // Increase brightness by scaling up RGB values
                const factor = 0.5 / luminance; // Scale to at least 50% luminance
                r = Math.min(255, Math.floor(r * factor));
                g = Math.min(255, Math.floor(g * factor));
                b = Math.min(255, Math.floor(b * factor));
            }
            // Check if color is too similar to purple (RGB: ~80, 50, 120 for purple-ish)
            // If it's purplish and dark, shift it towards cyan/white
            const isPurplish = (r < 130 && b > 80 && b > g && Math.abs(b - r) < 80);
            if (isPurplish && luminance < 0.5) {
                // Shift towards cyan/white for better contrast
                r = Math.min(255, r + 100);
                g = Math.min(255, g + 100);
                b = Math.min(255, b + 50);
            }
            // Convert back to hex
            const toHex = (n) => {
                const hex = Math.round(n).toString(16);
                return hex.length === 1 ? '0' + hex : hex;
            };
            return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
        }
        // Get cheermote color based on tier
        function getCheermoteColor(tier) {
            const colors = {
                1: '#979797',      // Gray (1-99)
                100: '#9c3ee8',    // Purple (100-999)
                1000: '#1db2a5',   // Cyan (1000-4999)
                5000: '#0099fe',   // Blue (5000-9999)
                10000: '#f43021',  // Red (10000-99999)
                100000: '#ffa500'  // Gold (100000+)
            };
            const tierNum = parseInt(tier) || 1;
            if (tierNum >= 100000) return colors[100000];
            if (tierNum >= 10000) return colors[10000];
            if (tierNum >= 5000) return colors[5000];
            if (tierNum >= 1000) return colors[1000];
            if (tierNum >= 100) return colors[100];
            return colors[1];
        }
        // Handle message deletion
        function handleMessageDelete(event) {
            const messageId = event.message_id;
            const overlay = document.getElementById('chat-overlay');
            const messageElement = overlay.querySelector(`[data-message-id="${messageId}"]`);
            if (messageElement) {
                // Add deleted class to strike through the message
                messageElement.classList.add('deleted');
                // Enforce cap and save updated history
                enforceMessageCap(MAX_CHAT_MESSAGES);
                saveChatHistory();
            }
        }
        // Handle chat notifications (subs, resubs, sub gifts, etc.)
        function handleChatNotification(event) {
            // Only show subscription-related notifications
            const subNoticeTypes = [
                'sub', 'resub', 'sub_gift', 'community_sub_gift',
                'gift_paid_upgrade', 'prime_paid_upgrade', 'pay_it_forward'
            ];
            if (!subNoticeTypes.includes(event.notice_type)) {
                return; // Ignore non-subscription notifications
            }
            const overlay = document.getElementById('chat-overlay');
            // Clear placeholder text
            if (overlay.children.length === 1 && overlay.children[0].tagName === 'P') {
                clearPlaceholderOnly();
            }
            const notificationDiv = document.createElement('div');
            notificationDiv.className = 'system-message sub-notification';
            notificationDiv.setAttribute('data-message-id', event.message_id);
            // Format timestamp
            const now = new Date();
            const hours = now.getHours().toString().padStart(2, '0');
            const minutes = now.getMinutes().toString().padStart(2, '0');
            const seconds = now.getSeconds().toString().padStart(2, '0');
            const timestamp = `${hours}:${minutes}:${seconds}`;
            // Use system_message as the main text
            const systemMessage = event.system_message || '';
            // Add any user message if they included one
            let userMessage = '';
            if (event.message && event.message.text) {
                userMessage = `<div class="sub-user-message">${escapeHtml(event.message.text)}</div>`;
            }
            notificationDiv.innerHTML = `
                                            <span class="chat-timestamp">${timestamp}</span>
                                            <span class="sub-icon">⭐</span>
                                            ${escapeHtml(systemMessage)}
                                            ${userMessage}
                                        `;
            overlay.appendChild(notificationDiv);
            // Auto-scroll to bottom
            overlay.scrollTop = overlay.scrollHeight;
            // Enforce cap (remove oldest messages if needed)
            enforceMessageCap(50);
            // Save chat history
            saveChatHistory();
            // Add to activity feed for subscriptions
            if (['sub', 'resub', 'sub_gift'].includes(event.notice_type)) {
                const isGift = event.notice_type === 'sub_gift';
                const tier = event.sub_tier ? event.sub_tier.replace('tier_', '') : '1';
                const months = event.cumulative_months || 1;
                addActivity('subscription', {
                    name: event.chatter_user_name || event.chatter_user_login || 'Anonymous',
                    avatar: null
                }, {
                    is_gift: isGift,
                    tier: tier,
                    cumulative_months: months
                });
            }
        }
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        // Fullscreen toggle
        function toggleFullscreen() {
            const container = document.querySelector('.container');
            const overlay = document.getElementById('chat-overlay');
            const icon = document.getElementById('fullscreen-icon');
            let exitBtn = document.getElementById('fullscreen-exit');
            // If the exit button doesn't exist (was removed by history restore), create it
            if (!exitBtn) {
                exitBtn = document.createElement('button');
                exitBtn.id = 'fullscreen-exit';
                exitBtn.className = 'fullscreen-exit-btn';
                exitBtn.onclick = toggleFullscreen;
                exitBtn.title = 'Exit Fullscreen (ESC)';
                exitBtn.textContent = '✕';
                // Append to overlay so CSS positioning applies
                overlay.appendChild(exitBtn);
            }
            if (container.classList.contains('fullscreen-mode')) {
                // Exit fullscreen
                container.classList.remove('fullscreen-mode');
                overlay.classList.remove('fullscreen');
                // restore overlay to original parent if we moved it
                try {
                    if (window.__overlayOriginalParent) {
                        const origParent = window.__overlayOriginalParent;
                        const origNext = window.__overlayOriginalNextSibling;
                        if (origNext && origNext.parentNode === origParent) {
                            origParent.insertBefore(overlay, origNext);
                        } else {
                            origParent.appendChild(overlay);
                        }
                        delete window.__overlayOriginalParent;
                        delete window.__overlayOriginalNextSibling;
                    }
                } catch (e) { console.warn('Failed to restore overlay parent', e); }
                // remove global fullscreen lock
                try { document.documentElement.classList.remove('overlay-fullscreen'); } catch (e) { }
                exitBtn.style.display = 'none';
                icon.textContent = '⛶';
            } else {
                // Enter fullscreen
                container.classList.add('fullscreen-mode');
                overlay.classList.add('fullscreen');
                // move overlay to body so fixed positioning covers full viewport
                try {
                    if (!window.__overlayOriginalParent) {
                        window.__overlayOriginalParent = overlay.parentNode;
                        window.__overlayOriginalNextSibling = overlay.nextSibling;
                        document.body.appendChild(overlay);
                    }
                } catch (e) { console.warn('Failed to move overlay to body for fullscreen', e); }
                // add global fullscreen lock (prevent body scrolling, ensure full coverage)
                try { document.documentElement.classList.add('overlay-fullscreen'); } catch (e) { }
                // Ensure the exit button is visible and on top
                exitBtn.style.display = 'flex';
                exitBtn.style.visibility = 'visible';
                exitBtn.style.zIndex = 10000;
                icon.textContent = '⛶';
                // Scroll to bottom when entering fullscreen
                setTimeout(() => {
                    overlay.scrollTop = overlay.scrollHeight;
                }, 100);
            }
        }
        // ESC key to exit fullscreen
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && document.querySelector('.container').classList.contains('fullscreen-mode')) {
                toggleFullscreen();
            }
        });
        // Channel Points Automatic Reward handling
        function handleAutomaticReward(event) {
            const overlay = document.getElementById('chat-overlay');

            // Clear placeholder text
            if (overlay.children.length === 1 && overlay.children[0].tagName === 'P') {
                clearPlaceholderOnly();
            }
            const rewardDiv = document.createElement('div');
            rewardDiv.className = 'reward-message automatic-reward';
            // Get reward type display name
            const rewardTypeNames = {
                'send_highlighted_message': 'Highlighted Message',
                'single_message_bypass_sub_mode': 'Single Message in Sub-Only Mode',
                'send_gigantified_emote': 'Gigantified Emote',
                'random_sub_emote_unlock': 'Random Sub Emote Unlock',
                'chosen_sub_emote_unlock': 'Chosen Sub Emote Unlock',
                'chosen_modified_sub_emote_unlock': 'Modified Sub Emote Unlock',
                'message_effect': 'Message Effect',
                'gigantify_an_emote': 'Gigantify an Emote',
                'celebration': 'Celebration'
            };
            const rewardName = rewardTypeNames[event.reward.type] || event.reward.type;
            // Build message HTML with emotes if present
            let messageHtml = '';
            const redemptionText = extractTextFromEvent(event);
            if (event.message && event.message.text) {
                if (event.message.fragments) {
                    event.message.fragments.forEach(fragment => {
                        if (fragment.type === 'emote' && fragment.emote) {
                            messageHtml += `<img src="https://static-cdn.jtvnw.net/emoticons/v2/${fragment.emote.id}/default/dark/1.0" alt="${fragment.text}" title="${fragment.text}" style="vertical-align: middle;">`;
                        } else {
                            messageHtml += escapeHtml(fragment.text);
                        }
                    });
                } else {
                    messageHtml = escapeHtml(event.message.text);
                }
            }
            // Check if there's a recent chat message that matches this redemption (bidirectional deduplication)
            try {
                if (consumeMatchingChatMessage(
                    event.user_login || null,
                    event.user_name || null,
                    event.user_id || null,
                    redemptionText
                )) {
                    console.log('Found matching chat message, removing it to show redemption instead:', redemptionText);
                    const chatMessages = overlay.querySelectorAll('.chat-message');
                    for (let msg of chatMessages) {
                        const username = msg.querySelector('.chat-username')?.textContent?.replace(':', '');
                        const text = msg.querySelector('.chat-text')?.textContent;
                        if (text && text.trim() === redemptionText && username &&
                            (username.toLowerCase() === (event.user_login || '').toLowerCase() ||
                                username.toLowerCase() === (event.user_name || '').toLowerCase())) {
                            msg.remove();
                            console.log('Removed matching chat message from DOM');
                            break;
                        }
                    }
                }
            } catch (e) {
                console.error('Error checking for matching chat message', e);
            }
            // Record this redemption so a near-simultaneous chat message from the same user with the same text can be ignored
            try {
                addRecentRedemption(
                    event.user_login || null,
                    event.user_name || null,
                    event.user_id || null,
                    redemptionText
                );
            } catch (e) {
                console.error('Error adding redemption to cache', e);
            }
            rewardDiv.innerHTML = `
                                    <div class="reward-header">
                                        <span class="reward-icon">⭐</span>
                                        <span class="reward-user">${escapeHtml(event.user_name)}</span>
                                        <span class="reward-text">redeemed</span>
                                        <span class="reward-name">${escapeHtml(rewardName)}</span>
                                        <span class="reward-cost">(${event.reward.channel_points} pts)</span>
                                    </div>
                                    ${messageHtml ? `<div class="reward-message-text">${messageHtml}</div>` : ''}
                                `;
            overlay.appendChild(rewardDiv);
            overlay.scrollTop = overlay.scrollHeight;
            // Enforce cap (remove oldest messages if needed)
            enforceMessageCap(50);
            // Save chat history
            saveChatHistory();
            // Add to activity feed
            const rewardTitle = rewardTypeNames[event.reward.type] || event.reward.type?.replace(/_/g, ' ') || 'Unknown Reward';
            addActivity('redemption', {
                name: event.user_name || event.user_login,
                avatar: null
            }, {
                reward_title: rewardTitle,
                cost: event.reward.cost
            });
        }
        // Channel Points Custom Reward handling
        function handleCustomReward(event) {
            const overlay = document.getElementById('chat-overlay');
            // Clear placeholder text
            if (overlay.children.length === 1 && overlay.children[0].tagName === 'P') {
                clearPlaceholderOnly();
            }
            const rewardDiv = document.createElement('div');
            rewardDiv.className = 'reward-message custom-reward';
            // Build reward image if available
            let imageHtml = '';
            if (event.reward && event.reward.image && event.reward.image.url_1x) {
                imageHtml = `<img src="${event.reward.image.url_1x}" class="reward-image" alt="${escapeHtml(event.reward.title)}">`;
            } else if (event.reward && event.reward.default_image && event.reward.default_image.url_1x) {
                imageHtml = `<img src="${event.reward.default_image.url_1x}" class="reward-image" alt="${escapeHtml(event.reward.title)}">`;
            }
            // Check if there's a recent chat message that matches this redemption (bidirectional deduplication)
            const redemptionText = extractTextFromEvent(event) || (event.user_input ? String(event.user_input).trim() : '');
            try {
                if (consumeMatchingChatMessage(
                    event.user_login || null,
                    event.user_name || null,
                    event.user_id || null,
                    redemptionText
                )) {
                    // Find and remove the matching chat message from DOM
                    const chatMessages = overlay.querySelectorAll('.chat-message');
                    for (let msg of chatMessages) {
                        const username = msg.querySelector('.chat-username')?.textContent?.replace(':', '');
                        const text = msg.querySelector('.chat-text')?.textContent;
                        if (text && text.trim() === redemptionText && username &&
                            (username.toLowerCase() === (event.user_login || '').toLowerCase() ||
                                username.toLowerCase() === (event.user_name || '').toLowerCase())) {
                            msg.remove();
                            console.log('Removed matching chat message from DOM');
                            break;
                        }
                    }
                }
            } catch (e) {
                console.error('Error checking for matching chat message', e);
            }
            // Record this redemption in cache so matching chat messages can be suppressed
            try {
                addRecentRedemption(
                    event.user_login || null,
                    event.user_name || null,
                    event.user_id || null,
                    redemptionText
                );
            } catch (e) {
                console.error('Error adding custom redemption to cache', e);
            }
            rewardDiv.innerHTML = `
                                    <div class="reward-header">
                                        ${imageHtml}
                                        <span class="reward-icon">🎁</span>
                                        <span class="reward-user">${escapeHtml(event.user_name)}</span>
                                        <span class="reward-text">redeemed</span>
                                        <span class="reward-name">${escapeHtml(event.reward.title)}</span>
                                        <span class="reward-cost">(${event.reward.cost} pts)</span>
                                    </div>
                                    ${event.reward.prompt ? `<div class="reward-prompt">${escapeHtml(event.reward.prompt)}</div>` : ''}
                                    ${event.user_input ? `<div class="reward-message-text">${escapeHtml(event.user_input)}</div>` : ''}
                                `;
            overlay.appendChild(rewardDiv);
            overlay.scrollTop = overlay.scrollHeight;
            // Enforce cap (remove oldest messages if needed)
            enforceMessageCap(50);
            // Save chat history
            saveChatHistory();
            // Add to activity feed
            addActivity('redemption', {
                name: event.user_name || event.user_login,
                avatar: null
            }, {
                reward_title: event.reward?.title || 'Custom Reward',
                cost: event.reward?.cost
            });
        }
        // Raid event handling
        function handleRaidEvent(event) {
            const overlay = document.getElementById('chat-overlay');
            // Clear placeholder text
            if (overlay.children.length === 1 && overlay.children[0].tagName === 'P') {
                clearPlaceholderOnly();
            }
            const raidDiv = document.createElement('div');
            raidDiv.className = 'system-message raid';
            const viewerText = event.viewers === 1 ? 'viewer' : 'viewers';
            raidDiv.innerHTML = `
                                    <span style="font-weight: bold; color: #ff6b6b;">🎯 RAID!</span>
                                    <span style="font-weight: bold; color: #ffd700;">${escapeHtml(event.from_broadcaster_user_name)}</span>
                                    is raiding with <span style="font-weight: bold; color: #ffd700;">${event.viewers.toLocaleString()}</span> ${viewerText}!
                                `;
            overlay.appendChild(raidDiv);
            overlay.scrollTop = overlay.scrollHeight;
            // Enforce cap using helper (default 50 messages)
            enforceMessageCap(50);
            // Save chat history
            saveChatHistory();
            // Add to activity feed
            addActivity('raid', {
                name: event.from_broadcaster_user_name,
                avatar: null
            }, {
                viewers: event.viewers
            });
        }
        // Bits event handling
        function handleBitsEvent(event) {
            // Cache this bits event for deduplication against chat messages
            const messageText = (event.type === 'cheer' && event.message && event.message.text) ? event.message.text : '';
            try {
                addRecentBitsEvent(
                    event.user_login || null,
                    event.user_name || null,
                    event.user_id || null,
                    messageText
                );
            } catch (e) {
                console.error('Error caching bits event', e);
            }
            const overlay = document.getElementById('chat-overlay');
            // Clear placeholder text
            if (overlay.children.length === 1 && overlay.children[0].tagName === 'P') {
                clearPlaceholderOnly();
            }
            const bitsDiv = document.createElement('div');
            bitsDiv.className = 'system-message bits';
            let bitsText = '';
            let emoji = '';
            switch (event.type) {
                case 'cheer':
                    emoji = '💎';
                    bitsText = `cheered ${event.bits} bits`;
                    break;
                case 'power_up':
                    emoji = '⚡';
                    bitsText = `used a power-up (${event.bits} bits)`;
                    break;
                default:
                    emoji = '💎';
                    bitsText = `used ${event.bits} bits`;
                    break;
            }
            let messageHtml = `
                                <span style="font-weight: bold; color: #9146ff;">${emoji} BITS!</span>
                                <span style="font-weight: bold; color: #ffd700;">${escapeHtml(event.user_name)}</span>
                                ${bitsText}
                            `;
            // Add the message content if it's a cheer
            if (event.type === 'cheer' && event.message && event.message.text) {
                messageHtml += `<div style="margin-top: 4px; font-style: italic; color: #e6e6e6;">${escapeHtml(event.message.text)}</div>`;
            }
            bitsDiv.innerHTML = messageHtml;
            overlay.appendChild(bitsDiv);
            overlay.scrollTop = overlay.scrollHeight;
            // Enforce cap using helper (default 50 messages)
            enforceMessageCap(50);
            // Save chat history
            saveChatHistory();
            // Add to activity feed
            const message = (event.type === 'cheer' && event.message && event.message.text) ? event.message.text : '';
            addActivity('cheer', {
                name: event.user_name,
                avatar: null
            }, {
                bits: event.bits,
                message: message || null
            });
        }
        // Send chat message function
        async function sendChatMessage() {
            const input = document.getElementById('message-input');
            const sendBtn = document.getElementById('send-message-btn');
            const message = input.value.trim();
            if (!message) {
                return;
            }
            
            // Check if connected
            if (!ircWs || ircWs.readyState !== WebSocket.OPEN || !ircJoined) {
                Toastify({
                    text: 'Not connected to chat. Please wait for connection.',
                    duration: 3000,
                    gravity: 'top',
                    position: 'right',
                    style: { background: 'linear-gradient(to right, #ff5f6d, #ffc371)' }
                }).showToast();
                return;
            }
            // Disable input and button while sending
            input.disabled = true;
            sendBtn.disabled = true;
            sendBtn.textContent = 'Sending...';
            try {
                // Send IRC PRIVMSG
                const channel = `#${CONFIG.USER_LOGIN.toLowerCase()}`;
                ircWs.send(`PRIVMSG ${channel} :${message}`);
                // Clear input on success
                input.value = '';
                // IRC doesn't echo back our PRIVMSG, so we need to display it ourselves
                // Create a synthetic event to display our message
                const ourEvent = {
                    chatter_user_id: CONFIG.USER_ID,
                    chatter_user_login: CONFIG.USER_LOGIN,
                    chatter_user_name: CONFIG.USER_LOGIN,
                    color: '#9146FF',
                    badges: [],
                    message: {
                        text: message,
                        fragments: [{ type: 'text', text: message }]
                    },
                    message_id: 'local-' + Date.now(),
                    message_type: 'text'
                };
                // Display our own message
                handleChatMessage(ourEvent);
            } catch (error) {
                console.error('Error sending message:', error);
                Toastify({
                    text: `Failed to send message: ${error.message}`,
                    duration: 5000,
                    gravity: 'top',
                    position: 'right',
                    style: { background: 'linear-gradient(to right, #ff5f6d, #ffc371)' }
                }).showToast();
            } finally {
                // Re-enable input and button
                input.disabled = false;
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send';
                input.focus();
            }
        }
        // Allow Enter key to send message
        document.addEventListener('DOMContentLoaded', () => {
            const input = document.getElementById('message-input');
            if (input) {
                input.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        sendChatMessage();
                    }
                });
            }
        });
        // Initialize
        async function initializeApp() {
            // Load settings from server first
            await loadSettingsFromServer();
            // Load chat history (only once, and only if overlay is empty)
            await loadChatHistory();
            // Load activity feed
            await loadActivityFeed();
            // Initialize UI with server settings
            migrateOldFilters();
            renderFilters();
            renderNicknames();
            initFilterCollapse();
            initImportExportUI();
            await fetchBadges(); // Fetch badge data (wait so badges are available before connecting)
            // Validate token on startup to get accurate expires_in
            await validateToken();
            // Connect both IRC and EventSub WebSockets
            connectIRCWebSocket();
            connectEventSubWebSocket();
            updateTokenTimer();
            // Initialize presence checkbox and state (IRC-based)
            try {
                const checkbox = document.getElementById('notify-joins-checkbox');
                presenceEnabled = loadPresenceSetting();
                if (checkbox) {
                    checkbox.checked = presenceEnabled;
                    checkbox.addEventListener('change', (e) => {
                        presenceEnabled = !!e.target.checked;
                        savePresenceSetting(presenceEnabled);
                        console.log(`Presence notifications ${presenceEnabled ? 'enabled' : 'disabled'} (IRC-based)`);
                    });
                }
            } catch (e) {
                console.error('Error initializing presence setting', e);
            }
        }
        // Start initialization
        initializeApp();
        // Update token timer every second
        setInterval(updateTokenTimer, 1000);
        // Re-validate token every 30 minutes to keep expires_in accurate
        setInterval(async () => {
            if (!isRefreshing) {
                await validateToken();
            }
        }, 30 * 60 * 1000); // 30 minutes
    </script>
    <?php endif; ?>
    </div>
    <footer class="page-footer">
        <p>&copy; 2023–<?php echo date('Y'); ?> BotOfTheSpecter. All rights reserved.<br>
            BotOfTheSpecter is a project operated under the business name "YourStreamingTools", registered in Australia
            (ABN 20 447 022 747).</p>
    </footer>
</body>

</html>