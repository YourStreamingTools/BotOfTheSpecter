<?php
require_once "/var/www/config/twitch.php";

session_start();

// Handle OAuth callback from StreamersConnect
if (isset($_GET['auth_data'])) {
    $authData = json_decode(base64_decode($_GET['auth_data']), true);
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
    $response = curl_exec($ch);
    curl_close($ch);
    $token_response = json_decode($response, true);
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
    <title>YourChat - Custom Twitch Chat Overlay</title>
    <link rel="stylesheet" href="style.css?v=<?php echo $cssVersion; ?>">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
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
                $scopes = 'user:read:chat user:write:chat channel:read:redemptions moderator:read:chatters bits:read';
                $authUrl = 'https://streamersconnect.com/?' . http_build_query([
                    'service' => 'twitch',
                    'login' => 'yourchat.botofthespecter.com',
                    'scopes' => $scopes,
                    'return_url' => 'https://yourchat.botofthespecter.com/index.php'
                ]);
                ?>
                <a href="<?php echo htmlspecialchars($authUrl); ?>" class="login-btn">Login with Twitch</a>
                <p class="info-text" style="margin-top: 1rem; font-size: 0.9rem; opacity: 0.7;">Authentication powered by StreamersConnect</p>
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
                    <p class="settings-description">Export your filters to a file or import from a previously saved file.</p>
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
            <input type="text" id="message-input" class="message-input" placeholder="Send a message to your chat..." maxlength="500">
            <button class="send-message-btn" id="send-message-btn" onclick="sendChatMessage()" title="Send Message">Send</button>
        </div>
        <script>
            // Configuration
            const CONFIG = {
                ACCESS_TOKEN: '<?php echo $_SESSION['access_token']; ?>',
                USER_ID: '<?php echo $_SESSION['user_id']; ?>',
                USER_LOGIN: '<?php echo $_SESSION['user_login']; ?>',
                TOKEN_CREATED_AT: <?php echo $_SESSION['token_created_at']; ?>,
                CLIENT_ID: '<?php echo $clientID; ?>',
                TOKEN_REFRESH_INTERVAL: 4 * 60 * 60 * 1000, // 4 hours in milliseconds
                EVENTSUB_WS_URL: 'wss://eventsub.wss.twitch.tv/ws'
            };
            // State management
            let ws = null;
            let sessionId = null;
            let accessToken = CONFIG.ACCESS_TOKEN;
            let tokenCreatedAt = CONFIG.TOKEN_CREATED_AT;
            let reconnectAttempts = 0;
            let maxReconnectAttempts = 5;
            let keepaliveTimeoutHandle = null;
            let keepaliveTimeoutSeconds = 10; // Default, will be updated from session
            let badgeCache = {}; // Cache for badge URLs
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
            // Presence settings (API-only)
            const PRESENCE_JOIN_KEY = 'notify_join_leave';
            let presenceEnabled = false;
            let presencePollHandle = null;
            let lastChatters = new Set(); // API-confirmed chatters
            let messageBasedChatters = new Set(); // Users detected via first message only
            // Tracks consecutive missed polls for users before announcing they left
            let presenceMissCounts = {};
            // Presence polling: 3s interval optimized for Twitch's 800 points/minute rate limit
            const PRESENCE_API_INTERVAL_MS = 3 * 1000;
            // Rate limit tracking
            let rateLimitRemaining = 800;
            let rateLimitReset = 0;
            // Poll/backoff state
            const presenceBaseInterval = PRESENCE_API_INTERVAL_MS;
            let presenceCurrentInterval = presenceBaseInterval;
            let presenceBackoffAttempts = 0;
            const PRESENCE_MAX_BACKOFF_MS = 5 * 60 * 1000;
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
                    overlay.innerHTML = '';
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
                const msgs = overlay.querySelectorAll('.chat-message, .reward-message, .system-message');
                if (msgs.length > 100) {
                    // Remove oldest messages (first ones in DOM order, skip exit button)
                    for (let i = 0; i < msgs.length && msgs.length > 100; i++) {
                        const node = msgs[i];
                        // Skip exit button and presence summary (non-join/leave system messages)
                        if (!node.classList.contains('fullscreen-exit-btn') &&
                            !(node.classList.contains('system-message') && !node.classList.contains('join') && !node.classList.contains('leave'))) {
                            if (node && node.parentNode) node.parentNode.removeChild(node);
                        }
                    }
                }
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
                    overlay.innerHTML = '';
                    if (exitBtn) overlay.appendChild(exitBtn);
                }
                const div = document.createElement('div');
                div.className = 'system-message join';
                div.textContent = text;
                const ref = exitBtn ? exitBtn.nextSibling : overlay.firstChild;
                overlay.insertBefore(div, ref);
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
            // Fetch current chatters using Helix API with rate limit monitoring
            async function fetchChattersFromAPI() {
                try {
                    const url = `https://api.twitch.tv/helix/chat/chatters?broadcaster_id=${CONFIG.USER_ID}&moderator_id=${CONFIG.USER_ID}`;
                    const resp = await fetch(url, {
                        headers: {
                            'Authorization': `Bearer ${accessToken}`,
                            'Client-Id': CONFIG.CLIENT_ID
                        }
                    });
                    // Track rate limit headers
                    const rateLimitHeaderRemaining = resp.headers.get('Ratelimit-Remaining');
                    const rateLimitHeaderReset = resp.headers.get('Ratelimit-Reset');
                    if (rateLimitHeaderRemaining) {
                        rateLimitRemaining = parseInt(rateLimitHeaderRemaining, 10) || rateLimitRemaining;
                    }
                    if (rateLimitHeaderReset) {
                        rateLimitReset = parseInt(rateLimitHeaderReset, 10) || rateLimitReset;
                    }
                    const status = resp.status;
                    const data = await resp.json().catch(() => null);
                    if (!resp.ok) {
                        console.warn('Chatters API returned error:', data || status);
                        // If the token is missing the moderator scope stop polling and inform the user
                        if (data && (data.status === 401 || data.status === 403) && typeof data.message === 'string' && data.message.toLowerCase().includes('missing scope')) {
                            try { showSystemMessage('Presence API requires the moderator:read:chatters scope. Please re-authorize with that scope.', 'error'); } catch (e) { console.warn('Unable to show system message', e); }
                            stopPresenceAPI();
                            return { ok: false, status };
                        }
                        if (status === 429) {
                            return { ok: false, status, rateLimitReset };
                        }
                        return { ok: false, status, data };
                    }
                    const set = new Set();
                    if (data && Array.isArray(data.data)) {
                        data.data.forEach(u => {
                            const login = (u.user_login || u.user_name || u.user_id || '').toString().toLowerCase();
                            if (login) set.add(login);
                        });
                    }
                    return { ok: true, set, rateLimitRemaining, rateLimitReset };
                } catch (err) {
                    console.error('Error fetching chatters from API:', err);
                    return { ok: false, status: 0, error: err };
                }
            }
            function startPresenceAPI() {
                if (presencePollHandle) return;
                // Initial fetch to establish baseline
                (async () => {
                    const initialResp = await fetchChattersFromAPI();
                    if (initialResp && initialResp.ok && initialResp.set) {
                        lastChatters = initialResp.set;
                        try {
                            const arr = Array.from(initialResp.set || []);
                            if (arr.length === 0) {
                                setPresenceMessage('No chatters present right now');
                            } else {
                                const preview = arr.slice(0, 20);
                                const more = arr.length > preview.length ? ` and ${arr.length - preview.length} more` : '';
                                setPresenceMessage(`Currently in chat: ${preview.join(', ')}${more}`);
                            }
                        } catch (e) {
                            console.warn('Unable to display initial chatters list', e);
                        }
                    } else {
                        lastChatters = new Set();
                    }
                })();
                // Poll loop with dynamic interval adjustment
                const pollOnce = async () => {
                    if (!presenceEnabled) return;
                    // Check rate limit and delay if needed
                    const now = Math.floor(Date.now() / 1000);
                    if (rateLimitRemaining < 50 && rateLimitReset > now) {
                        const delayUntilReset = (rateLimitReset - now + 5) * 1000;
                        console.log(`Rate limit low (${rateLimitRemaining} remaining), waiting ${Math.round(delayUntilReset / 1000)}s for reset`);
                        presencePollHandle = setTimeout(pollOnce, delayUntilReset);
                        return;
                    }
                    const resp = await fetchChattersFromAPI();
                    if (!resp) {
                        presencePollHandle = setTimeout(pollOnce, presenceCurrentInterval);
                        return;
                    }
                    if (!resp.ok) {
                        if (resp.status === 429) {
                            let backoffMs;
                            if (resp.rateLimitReset && resp.rateLimitReset > now) {
                                backoffMs = (resp.rateLimitReset - now + 2) * 1000;
                                console.log(`Rate limited, waiting ${Math.round(backoffMs / 1000)}s until reset`);
                            } else {
                                presenceBackoffAttempts++;
                                backoffMs = Math.min(presenceBaseInterval * Math.pow(2, presenceBackoffAttempts), PRESENCE_MAX_BACKOFF_MS);
                                backoffMs += Math.floor(Math.random() * 1000);
                            }
                            presenceCurrentInterval = backoffMs;
                            showSystemMessage('Presence API rate-limited — backing off', 'error');
                            presencePollHandle = setTimeout(pollOnce, backoffMs);
                            return;
                        }
                        presencePollHandle = setTimeout(pollOnce, presenceCurrentInterval);
                        return;
                    }
                    // Success: reset backoff and adjust interval based on rate limit
                    presenceBackoffAttempts = 0;
                    if (rateLimitRemaining > 400) {
                        presenceCurrentInterval = Math.max(2 * 1000, presenceBaseInterval * 0.7);
                    } else if (rateLimitRemaining > 200) {
                        presenceCurrentInterval = presenceBaseInterval;
                    } else {
                        presenceCurrentInterval = presenceBaseInterval * 1.7;
                    }
                    const current = resp.set;
                    // Determine joins and leaves
                    const joins = [];
                    current.forEach(login => {
                        if (!lastChatters.has(login)) joins.push(login);
                    });
                    const leavesCandidates = [];
                    lastChatters.forEach(login => {
                        if (!current.has(login)) leavesCandidates.push(login);
                    });
                    // Announce joins immediately and reset miss counts
                    joins.forEach(login => {
                        // Only announce if they weren't already detected via message
                        if (!messageBasedChatters.has(login)) {
                            showSystemMessage(`${login} joined the chat`, 'join');
                        } else {
                            // Move from message-based to API-confirmed tracking
                            messageBasedChatters.delete(login);
                        }
                        presenceMissCounts[login] = 0;
                        lastChatters.add(login);
                    });
                    // Track leave candidates (require 2 consecutive misses to announce)
                    // Only track leaves for API-confirmed users, not message-based joins
                    leavesCandidates.forEach(login => {
                        // Skip users who were only detected via message and never confirmed by API
                        if (messageBasedChatters.has(login)) {
                            return; // Don't track leaves for message-only users
                        }
                        presenceMissCounts[login] = (presenceMissCounts[login] || 0) + 1;
                        if (presenceMissCounts[login] >= 2) {
                            showSystemMessage(`${login} left the chat`, 'leave');
                            lastChatters.delete(login);
                            delete presenceMissCounts[login];
                        }
                    });
                    // Reset miss counts for users still present
                    current.forEach(login => {
                        presenceMissCounts[login] = 0;
                    });
                    presencePollHandle = setTimeout(pollOnce, presenceCurrentInterval);
                };
                // Start polling
                presencePollHandle = setTimeout(pollOnce, presenceCurrentInterval);
            }
            function stopPresenceAPI() {
                if (presencePollHandle) {
                    clearTimeout(presencePollHandle);
                    presencePollHandle = null;
                }
                lastChatters = new Set();
                messageBasedChatters = new Set();
                presenceBackoffAttempts = 0;
                presenceCurrentInterval = presenceBaseInterval;
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
                            'Client-Id': '<?php echo $clientID; ?>'
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
                        // Preserve any non-message nodes like fullscreen button
                        const exitBtn = overlay.querySelector('.fullscreen-exit-btn');
                        // Clear placeholder but keep exit button
                        overlay.innerHTML = '';
                        if (exitBtn) overlay.appendChild(exitBtn);
                        // Restore messages
                        result.messages.forEach(encoded => {
                            try {
                                const html = decodeURIComponent(escape(atob(encoded)));
                                const div = document.createElement('div');
                                div.innerHTML = html;
                                overlay.appendChild(div.firstChild);
                            } catch (decodeError) {
                                console.log('Skipping invalid history entry');
                            }
                        });
                        overlay.scrollTop = overlay.scrollHeight;
                    }
                } catch (error) {
                    console.error('Error loading chat history:', error);
                }
            }
            // Filter management
            function renderFilters() {
                // usernames
                const users = loadFiltersUsernames();
                const listUsers = document.getElementById('filter-list-users');
                listUsers.innerHTML = '';
                users.forEach(filter => {
                    const tag = document.createElement('div');
                    tag.className = 'filter-tag';
                    tag.innerHTML = `${filter} <button onclick="removeFilter('user','${filter.replace(/'/g, "\\'")}')">×</button>`;
                listUsers.appendChild(tag);
            });
            // messages
            const msgs = loadFiltersMessages();
            const listMsgs = document.getElementById('filter-list-msg');
            listMsgs.innerHTML = '';
            msgs.forEach(filter => {
                const tag = document.createElement('div');
                tag.className = 'filter-tag';
                tag.innerHTML = `${filter} <button onclick="removeFilter('message','${filter.replace(/'/g, "\\'")}')">×</button>`;
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
        // Token management
        function updateTokenTimer() {
            const now = Math.floor(Date.now() / 1000);
            const elapsed = now - tokenCreatedAt;
            const remaining = (4 * 60 * 60) - elapsed; // 4 hours in seconds
            // Refresh token 30 seconds before expiry
            if (remaining <= 30 && remaining > 0) {
                refreshToken();
                return;
            }
            if (remaining <= 0) {
                // Token expired without successful refresh
                document.getElementById('token-timer').textContent = 'Refreshing...';
                refreshToken();
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
            console.log('Refreshing access token...');
            updateStatus(false, 'Refreshing Token...');
            try {
                const response = await fetch('?action=refresh_token');
                const data = await response.json();
                if (data.success) {
                    accessToken = data.access_token;
                    tokenCreatedAt = data.created_at;
                    console.log('Token refreshed successfully');
                    // Reconnect WebSocket with new token
                    if (ws) {
                        ws.close();
                    }
                    connectWebSocket();
                } else {
                    console.error('Token refresh failed:', data.error);
                    handleSessionExpiry();
                }
            } catch (error) {
                console.error('Error refreshing token:', error);
                handleSessionExpiry();
            } finally {
                isRefreshing = false;
            }
        }
        async function handleSessionExpiry() {
            console.log('Token refresh failed - ending session...');
            // Disconnect WebSocket
            if (ws) {
                ws.close();
                ws = null;
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
                if (ws) {
                    ws.close();
                    ws = null;
                }
                await fetch('?action=expire_session');
            } catch (err) {
                console.error('Logout error:', err);
            }
            // Reload to show login view
            window.location.reload();
        }
        // WebSocket management
        function updateStatus(connected, text) {
            const statusLight = document.getElementById('ws-status');
            const statusText = document.getElementById('ws-status-text');
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
        function connectWebSocket() {
            if (ws) {
                ws.close();
            }
            updateStatus(false, 'Connecting...');
            ws = new WebSocket(CONFIG.EVENTSUB_WS_URL);
            ws.onopen = () => {
                console.log('WebSocket connected');
            };
            ws.onmessage = async (event) => {
                const message = JSON.parse(event.data);
                // Update keepalive timeout value if provided in this message
                if (message.payload?.session?.keepalive_timeout_seconds) {
                    keepaliveTimeoutSeconds = message.payload.session.keepalive_timeout_seconds;
                }
                // Reset keepalive timeout on EVERY message received
                setupKeepaliveTimeout(keepaliveTimeoutSeconds);
                await handleWebSocketMessage(message);
            };
            ws.onerror = (error) => {
                console.error('WebSocket error:', error);
                updateStatus(false, 'Error');
            };
            ws.onclose = () => {
                console.log('WebSocket closed');
                updateStatus(false, 'Disconnected');
                // Attempt to reconnect
                if (reconnectAttempts < maxReconnectAttempts) {
                    reconnectAttempts++;
                    setTimeout(connectWebSocket, 5000);
                }
            };
        }
        async function handleWebSocketMessage(message) {
            const metadata = message.metadata;
            const payload = message.payload;
            if (!metadata || !metadata.message_type) {
                return;
            }
            switch (metadata.message_type) {
                case 'session_welcome':
                    sessionId = payload.session.id;
                    console.log('Session ID:', sessionId);
                    updateStatus(true, 'Connected');
                    reconnectAttempts = 0;
                    // Subscribe to chat messages
                    await subscribeToEvents();
                    // Setup keepalive timeout
                    const keepaliveTimeout = payload.session.keepalive_timeout_seconds;
                    setupKeepaliveTimeout(keepaliveTimeout);
                    break;
                case 'session_keepalive':
                    break;
                case 'notification':
                    if (payload.subscription.type === 'channel.chat.message') {
                        handleChatMessage(payload.event);
                    } else if (payload.subscription.type === 'channel.channel_points_automatic_reward_redemption.add') {
                        handleAutomaticReward(payload.event);
                    } else if (payload.subscription.type === 'channel.channel_points_custom_reward_redemption.add') {
                        handleCustomReward(payload.event);
                    } else if (payload.subscription.type === 'channel.raid') {
                        handleRaidEvent(payload.event);
                    } else if (payload.subscription.type === 'channel.bits.use') {
                        handleBitsEvent(payload.event);
                    } else if (payload.subscription.type === 'channel.chat.message_delete') {
                        handleMessageDelete(payload.event);
                    } else if (payload.subscription.type === 'channel.chat.notification') {
                        handleChatNotification(payload.event);
                    }
                    break;
                case 'session_reconnect':
                    console.log('Reconnecting to new session...');
                    const reconnectUrl = payload.session.reconnect_url;
                    if (ws) {
                        ws.close();
                    }
                    ws = new WebSocket(reconnectUrl);
                    setupWebSocketHandlers(ws);
                    break;
                case 'revocation':
                    console.error('Subscription revoked:', payload);
                    updateStatus(false, 'Subscription Revoked');
                    break;
            }
        }
        function setupKeepaliveTimeout(seconds) {
            if (keepaliveTimeoutHandle) {
                clearTimeout(keepaliveTimeoutHandle);
            }
            // Add 3 second buffer to prevent race conditions
            const timeoutDuration = (seconds + 3) * 1000;
            keepaliveTimeoutHandle = setTimeout(() => {
                console.error('Keepalive timeout - no message received');
                if (ws) {
                    ws.close();
                }
            }, timeoutDuration);
        }
        async function subscribeToEvents() {
            const subscriptions = [
                {
                    type: 'channel.chat.message',
                    version: '1',
                    condition: {
                        broadcaster_user_id: CONFIG.USER_ID,
                        user_id: CONFIG.USER_ID
                    }
                },
                {
                    type: 'channel.channel_points_automatic_reward_redemption.add',
                    version: '2',
                    condition: {
                        broadcaster_user_id: CONFIG.USER_ID
                    }
                },
                {
                    type: 'channel.channel_points_custom_reward_redemption.add',
                    version: '1',
                    condition: {
                        broadcaster_user_id: CONFIG.USER_ID
                    }
                },
                {
                    type: 'channel.raid',
                    version: '1',
                    condition: {
                        to_broadcaster_user_id: CONFIG.USER_ID
                    }
                },
                {
                    type: 'channel.bits.use',
                    version: '1',
                    condition: {
                        broadcaster_user_id: CONFIG.USER_ID
                    }
                },
                {
                    type: 'channel.chat.message_delete',
                    version: '1',
                    condition: {
                        broadcaster_user_id: CONFIG.USER_ID,
                        user_id: CONFIG.USER_ID
                    }
                },
                {
                    type: 'channel.chat.notification',
                    version: '1',
                    condition: {
                        broadcaster_user_id: CONFIG.USER_ID,
                        user_id: CONFIG.USER_ID
                    }
                }
            ];
            let successCount = 0;
            for (const sub of subscriptions) {
                const subscriptionData = {
                    ...sub,
                    transport: {
                        method: 'websocket',
                        session_id: sessionId
                    }
                };
                try {
                    const response = await fetch('https://api.twitch.tv/helix/eventsub/subscriptions', {
                        method: 'POST',
                        headers: {
                            'Authorization': `Bearer ${accessToken}`,
                            'Client-Id': CONFIG.CLIENT_ID,
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(subscriptionData)
                    });
                    const result = await response.json();
                    if (response.ok) {
                        console.log(`Successfully subscribed to ${sub.type}`);
                        successCount++;
                    } else {
                        console.error(`Subscription failed for ${sub.type}:`, result);
                    }
                } catch (error) {
                    console.error(`Subscription error for ${sub.type}:`, error);
                }
            }
            if (successCount > 0) {
                const overlay = document.getElementById('chat-overlay');
                // Only show "Connected to chat" if there are no messages loaded (including system messages)
                const hasMessages = overlay.querySelector('.chat-message, .reward-message, .system-message');
                if (!hasMessages) {
                    // preserve exit button if present
                    const exitBtn = overlay.querySelector('.fullscreen-exit-btn');
                    overlay.innerHTML = '';
                    if (exitBtn) overlay.appendChild(exitBtn);
                    const p = document.createElement('p');
                    p.style.color = '#999';
                    p.style.textAlign = 'center';
                    p.textContent = 'Connected to chat';
                    overlay.appendChild(p);
                }
            } else {
                updateStatus(false, 'Subscription Failed');
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
            if (presenceEnabled && userLogin && !lastChatters.has(userLogin) && !messageBasedChatters.has(userLogin)) {
                // User hasn't been detected by presence system, mark them as joined via message
                messageBasedChatters.add(userLogin);
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
                overlay.innerHTML = '';
            }
            const messageDiv = document.createElement('div');
            messageDiv.className = 'chat-message';
            messageDiv.setAttribute('data-message-id', event.message_id);
            // Add special classes based on message_type
            if (event.message_type) {
                messageDiv.setAttribute('data-message-type', event.message_type);
                if (event.message_type === 'user_intro') {
                    messageDiv.classList.add('first-time-chatter');
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
                const replyUsername = (settings?.nicknames && settings.nicknames[event.reply.parent_user_login?.toLowerCase()]) || event.reply.parent_user_name || event.reply.parent_user_login;
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
            if (event.message && event.message.fragments) {
                event.message.fragments.forEach(fragment => {
                    if (fragment.type === 'text') {
                        messageTextHtml += escapeHtml(fragment.text);
                    } else if (fragment.type === 'emote' && fragment.emote) {
                        const emoteClass = event.message_type === 'power_ups_gigantified_emote' ? 'chat-emote gigantified' : 'chat-emote';
                        messageTextHtml += `<img class="${emoteClass}" src="https://static-cdn.jtvnw.net/emoticons/v2/${fragment.emote.id}/default/dark/1.0" alt="${escapeHtml(fragment.text)}" title="${escapeHtml(fragment.text)}" style="vertical-align: middle; height: 1.5em;">`;
                    } else if (fragment.type === 'cheermote' && fragment.cheermote) {
                        const cheermoteColor = getCheermoteColor(fragment.cheermote.tier || 1);
                        const cheermoteText = `${fragment.cheermote.prefix}${fragment.cheermote.bits}`;
                        messageTextHtml += `<span class="cheermote" data-tier="${fragment.cheermote.tier || 1}" style="color: ${cheermoteColor};" title="${fragment.cheermote.bits} Bits">${escapeHtml(cheermoteText)}</span>`;
                    } else if (fragment.type === 'mention' && fragment.mention) {
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
            messageHtml += `
                ${badgesHtml}
                <span class="chat-username" style="color: ${event.color || '#ffffff'}">${escapeHtml(displayName)}:</span>
                ${sharedChatIndicator}
                <span class="chat-text">${messageTextHtml}</span>
            `;
            messageDiv.innerHTML = messageHtml;
            overlay.appendChild(messageDiv);
            // Auto-scroll to bottom
            overlay.scrollTop = overlay.scrollHeight;
            // Limit messages to prevent memory issues
            if (overlay.children.length > 100) {
                overlay.removeChild(overlay.firstChild);
            }
            // Save chat history
            saveChatHistory();
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
                // Save updated history
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
                overlay.innerHTML = '';
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
            // Limit messages to prevent memory issues
            if (overlay.children.length > 100) {
                overlay.removeChild(overlay.firstChild);
            }
            // Save chat history
            saveChatHistory();
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
                overlay.innerHTML = '';
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
            // Limit messages
            if (overlay.children.length > 100) {
                overlay.removeChild(overlay.firstChild);
            }
            // Save chat history
            saveChatHistory();
        }
        // Channel Points Custom Reward handling
        function handleCustomReward(event) {
            const overlay = document.getElementById('chat-overlay');
            // Clear placeholder text
            if (overlay.children.length === 1 && overlay.children[0].tagName === 'P') {
                overlay.innerHTML = '';
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
            // Limit messages
            if (overlay.children.length > 100) {
                overlay.removeChild(overlay.firstChild);
            }
            // Save chat history
            saveChatHistory();
        }
        // Raid event handling
        function handleRaidEvent(event) {
            const overlay = document.getElementById('chat-overlay');
            // Clear placeholder text
            if (overlay.children.length === 1 && overlay.children[0].tagName === 'P') {
                overlay.innerHTML = '';
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
            // Limit messages
            const msgs = overlay.querySelectorAll('.chat-message, .reward-message, .system-message');
            if (msgs.length > 100) {
                for (let i = 0; i < msgs.length - 100; i++) {
                    const node = msgs[i];
                    if (!node.classList.contains('fullscreen-exit-btn') &&
                        !(node.classList.contains('system-message') && !node.classList.contains('join') && !node.classList.contains('leave') && !node.classList.contains('raid'))) {
                        if (node && node.parentNode) node.parentNode.removeChild(node);
                    }
                }
            }
            // Save chat history
            saveChatHistory();
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
                overlay.innerHTML = '';
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
            // Limit messages
            const msgs = overlay.querySelectorAll('.chat-message, .reward-message, .system-message');
            if (msgs.length > 100) {
                for (let i = 0; i < msgs.length - 100; i++) {
                    const node = msgs[i];
                    if (!node.classList.contains('fullscreen-exit-btn') &&
                        !(node.classList.contains('system-message') && !node.classList.contains('join') && !node.classList.contains('leave') && !node.classList.contains('raid') && !node.classList.contains('bits'))) {
                        if (node && node.parentNode) node.parentNode.removeChild(node);
                    }
                }
            }
            // Save chat history
            saveChatHistory();
        }
        // Send chat message function
        async function sendChatMessage() {
            const input = document.getElementById('message-input');
            const sendBtn = document.getElementById('send-message-btn');
            const message = input.value.trim();
            if (!message) {
                return;
            }
            // Disable input and button while sending
            input.disabled = true;
            sendBtn.disabled = true;
            sendBtn.textContent = 'Sending...';
            try {
                const response = await fetch('https://api.twitch.tv/helix/chat/messages', {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${CONFIG.ACCESS_TOKEN}`,
                        'Client-Id': '<?php echo $clientID; ?>',
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        broadcaster_id: CONFIG.USER_ID,
                        sender_id: CONFIG.USER_ID,
                        message: message
                    })
                });
                if (response.ok) {
                    const data = await response.json();
                    if (data.data && data.data[0] && data.data[0].is_sent) {
                        // Clear input on success
                        input.value = '';
                        // Message will appear via WebSocket event
                    } else {
                        throw new Error('Message not sent');
                    }
                } else if (response.status === 401) {
                    // Token expired, try to refresh
                    Toastify({
                        text: 'Session expired. Please refresh the page.',
                        duration: 5000,
                        gravity: 'top',
                        position: 'right',
                        backgroundColor: '#ff4444'
                    }).showToast();
                } else {
                    const errorData = await response.json().catch(() => ({}));
                    throw new Error(errorData.message || 'Failed to send message');
                }
            } catch (error) {
                console.error('Error sending message:', error);
                Toastify({
                    text: `Failed to send message: ${error.message}`,
                    duration: 5000,
                    gravity: 'top',
                    position: 'right',
                    backgroundColor: '#ff4444'
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
            // Load chat history first
            await loadChatHistory();
            // Load settings from server
            await loadSettingsFromServer();
            // Then initialize UI with server settings
            loadChatHistory();
            migrateOldFilters();
            renderFilters();
            renderNicknames();
            initFilterCollapse();
            initImportExportUI();
            fetchBadges(); // Fetch badge data
            connectWebSocket();
            updateTokenTimer();
            // Initialize presence checkbox and state (API-only)
            try {
                const checkbox = document.getElementById('notify-joins-checkbox');
                presenceEnabled = loadPresenceSetting();
                if (checkbox) {
                    checkbox.checked = presenceEnabled;
                    checkbox.addEventListener('change', (e) => {
                        presenceEnabled = !!e.target.checked;
                        savePresenceSetting(presenceEnabled);
                        if (presenceEnabled) startPresenceAPI(); else stopPresenceAPI();
                    });
                }
                // Start polling immediately if setting enabled
                if (presenceEnabled) startPresenceAPI();
            } catch (e) {
                console.error('Error initializing presence setting', e);
            }
        }
        // Start initialization
        initializeApp();
        // Update token timer every second
        setInterval(updateTokenTimer, 1000);
    </script>
    <?php endif; ?>
    </div>
    <footer class="page-footer">
        <p>&copy; 2023–<?php echo date('Y'); ?> BotOfTheSpecter. All rights reserved.<br>
        BotOfTheSpecter is a project operated under the business name "YourStreamingTools", registered in Australia (ABN 20 447 022 747).</p>
    </footer>
</body>
</html>