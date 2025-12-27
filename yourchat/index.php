<?php
require_once "/var/www/config/twitch.php";

session_start();

// Handle OAuth callback
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    // Exchange code for access token
    $token_url = 'https://id.twitch.tv/oauth2/token';
    $token_data = [
        'client_id' => $clientID,
        'client_secret' => $clientSecret,
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => 'https://yourchat.botofthespecter.com/index.php'
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
        // Get user info
        $user_url = 'https://api.twitch.tv/helix/users';
        $ch = curl_init($user_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token_response['access_token'],
            'Client-Id: ' . $clientID
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $user_response = curl_exec($ch);
        curl_close($ch);
        $user_data = json_decode($user_response, true);
        if (isset($user_data['data'][0])) {
            $_SESSION['user_id'] = $user_data['data'][0]['id'];
            $_SESSION['user_login'] = $user_data['data'][0]['login'];
            $_SESSION['user_display_name'] = $user_data['data'][0]['display_name'];
        }
    }
    // Redirect to clean URL
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

$isLoggedIn = isset($_SESSION['access_token']) && isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YourChat - Custom Twitch Chat Overlay</title>
    <?php
    // Cache busting for CSS file using file modification time
    $cssFile = __DIR__ . '/style.css';
    $cssVersion = file_exists($cssFile) ? filemtime($cssFile) : time();
    ?>
    <link rel="stylesheet" href="style.css?v=<?php echo $cssVersion; ?>">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
</head>
<body>
    <div class="container">
        <?php if (!$isLoggedIn): ?>
        <div class="login-container">
            <h1>YourChat - Custom Twitch Chat Overlay</h1>
            <p class="login-subtitle">Login with Twitch to customize your chat overlay</p>
            <?php
            $scopes = 'user:read:chat channel:read:redemptions';
            $authUrl = 'https://id.twitch.tv/oauth2/authorize?' . http_build_query([
                'client_id' => $clientID,
                'redirect_uri' => 'https://yourchat.botofthespecter.com/index.php',
                'response_type' => 'code',
                'scope' => $scopes
            ]);
            ?>
            <a href="<?php echo htmlspecialchars($authUrl); ?>" class="login-btn">Login with Twitch</a>
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
                    <span class="token-wrapper"><span class="token-label hide-on-narrow">Token:</span> <span id="token-timer">--:--</span></span>
                </div>
                <div class="compact-actions" aria-hidden="false">
                    <button class="clear-history-btn" onclick="clearChatHistory()" title="Clear Chat History" aria-label="Clear chat history">🗑️</button>
                    <button class="fullscreen-btn" onclick="toggleFullscreen()" title="Toggle Fullscreen" aria-label="Toggle fullscreen"><span id="fullscreen-icon">⛶</span></button>
                </div>
            </div>
        </div>
        <div class="settings-panel">
            <h3>Message Filters</h3>
            <p class="settings-description">
                Add phrases or usernames to filter out from the chat overlay. Messages matching these filters will not be displayed.
            </p>
            <input type="text" 
                   id="filter-input" 
                   class="filter-input" 
                   placeholder="Enter phrase to filter (press Enter to add)"
                   onkeypress="handleFilterInput(event)">
            <div class="filter-list" id="filter-list"></div>
        </div>
        <div class="chat-overlay" id="chat-overlay">
            <button class="fullscreen-exit-btn" id="fullscreen-exit" onclick="toggleFullscreen()" title="Exit Fullscreen (ESC)">
                ✕
            </button>
            <p class="chat-placeholder">Connecting to chat...</p>
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
            function addRecentRedemption(user_login, user_name, text) {
                const entry = {
                    user_login: user_login ? String(user_login).toLowerCase() : null,
                    user_name: user_name ? String(user_name).toLowerCase() : null,
                    text: text ? String(text).trim() : '',
                    ts: Date.now()
                };
                recentRedemptions.push(entry);
                // Keep cache small: remove entries older than 10s
                const cutoff = Date.now() - 10000;
                recentRedemptions = recentRedemptions.filter(e => e.ts >= cutoff);
            }
            function consumeMatchingRedemption(chatter_login, chatter_name, text) {
                if (!text) return false;
                const t = String(text).trim();
                const login = chatter_login ? String(chatter_login).toLowerCase() : null;
                const name = chatter_name ? String(chatter_name).toLowerCase() : null;
                const now = Date.now();
                // Consider matches within last 5 seconds
                for (let i = 0; i < recentRedemptions.length; i++) {
                    const e = recentRedemptions[i];
                    if (now - e.ts > 5000) continue;
                    if (e.text === t && ((login && e.user_login === login) || (name && e.user_name === name))) {
                        // remove this entry and return true
                        recentRedemptions.splice(i, 1);
                        return true;
                    }
                }
                return false;
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
                    console.log('Badges loaded:', Object.keys(badgeCache));
                } catch (error) {
                    console.error('Error fetching badges:', error);
                }
            }
            // Load filters from cookies
            function loadFilters() {
                const filters = getCookie('chat_filters');
                return filters ? JSON.parse(filters) : [];
            }
            // Save filters to cookies
            function saveFilters(filters) {
                setCookie('chat_filters', JSON.stringify(filters), 365);
            }
            // Save chat history to localStorage (fallback to cookies for older browsers)
            function saveChatHistory() {
                try {
                    const overlay = document.getElementById('chat-overlay');
                    const messages = Array.from(overlay.children)
                        .filter(child => child.classList.contains('chat-message') || child.classList.contains('reward-message'))
                        .slice(-10) // Keep only last 10 messages
                        .map(msg => btoa(unescape(encodeURIComponent(msg.outerHTML)))); // Base64 encode
                    if (window.localStorage) {
                        localStorage.setItem('chat_history', JSON.stringify(messages));
                        // Keep cookie in sync as a fallback for other browsers
                        try { setCookie('chat_history', JSON.stringify(messages), 1); } catch (e) { /* ignore */ }
                    } else {
                        setCookie('chat_history', JSON.stringify(messages), 1); // Expire after 1 day
                    }
                } catch (error) {
                    console.error('Error saving chat history:', error);
                }
            }
            // Clear chat history
            function clearChatHistory() {
                const overlay = document.getElementById('chat-overlay');
                // Remove all messages from DOM
                const messages = overlay.querySelectorAll('.chat-message, .automatic-reward, .custom-reward');
                messages.forEach(msg => msg.remove());
                // Clear storage
                if (window.localStorage) {
                    try { localStorage.removeItem('chat_history'); } catch (e) { /* ignore */ }
                }
                // Always remove cookie backup as well
                try { document.cookie = 'chat_history=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/'; } catch (e) { /* ignore */ }
                // Add placeholder if chat is empty
                if (overlay.children.length === 0 || (overlay.children.length === 1 && overlay.children[0].classList.contains('fullscreen-exit-btn'))) {
                    const placeholder = document.createElement('p');
                    placeholder.className = 'chat-placeholder';
                    placeholder.textContent = 'Chat history cleared. Waiting for messages...';
                    overlay.appendChild(placeholder);
                }
            }
            // Load chat history from localStorage (fallback to cookies)
            function loadChatHistory() {
                let history = null;
                try {
                    if (window.localStorage && localStorage.getItem('chat_history')) {
                        history = localStorage.getItem('chat_history');
                    } else {
                        history = getCookie('chat_history');
                    }
                    if (history) {
                        const messages = JSON.parse(history);
                        const overlay = document.getElementById('chat-overlay');
                        // Preserve any non-message nodes like fullscreen button
                        const exitBtn = overlay.querySelector('.fullscreen-exit-btn');
                        // Clear placeholder but keep exit button
                        overlay.innerHTML = '';
                        if (exitBtn) overlay.appendChild(exitBtn);
                        // Restore messages
                        messages.forEach(encoded => {
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
                    // Clear corrupted history
                    if (window.localStorage) localStorage.removeItem('chat_history');
                    document.cookie = 'chat_history=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/';
                }
            }
            // Cookie helpers
            function setCookie(name, value, days) {
                const expires = new Date();
                expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));
                document.cookie = name + '=' + value + ';expires=' + expires.toUTCString() + ';path=/';
            }
            function getCookie(name) {
                const nameEQ = name + "=";
                const ca = document.cookie.split(';');
                for(let i = 0; i < ca.length; i++) {
                    let c = ca[i];
                    while (c.charAt(0) === ' ') c = c.substring(1, c.length);
                    if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
                }
                return null;
            }
            // Filter management
            function renderFilters() {
                const filters = loadFilters();
                const filterList = document.getElementById('filter-list');
                filterList.innerHTML = '';
                filters.forEach(filter => {
                    const tag = document.createElement('div');
                    tag.className = 'filter-tag';
                    tag.innerHTML = `
                        ${filter}
                        <button onclick="removeFilter('${filter.replace(/'/g, "\\'")}')">×</button>
                    `;
                    filterList.appendChild(tag);
                });
            }
            function handleFilterInput(event) {
                if (event.key === 'Enter') {
                    const input = document.getElementById('filter-input');
                    const value = input.value.trim();
                    if (value) {
                        const filters = loadFilters();
                        if (!filters.includes(value)) {
                            filters.push(value);
                            saveFilters(filters);
                            renderFilters();
                        }
                        input.value = '';
                    }
                }
            }
            function removeFilter(filter) {
                const filters = loadFilters().filter(f => f !== filter);
                saveFilters(filters);
                renderFilters();
            }
            function isMessageFiltered(event) {
                const filters = loadFilters();
                const messageText = event.message?.text?.toLowerCase() || '';
                const username = event.chatter_user_login?.toLowerCase() || '';
                return filters.some(filter => {
                    const filterLower = filter.toLowerCase();
                    // Check if message text contains the filter OR username matches exactly
                    return messageText.includes(filterLower) || username === filterLower;
                });
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
                        console.log('Keepalive received');
                        break;
                    case 'notification':
                        if (payload.subscription.type === 'channel.chat.message') {
                            handleChatMessage(payload.event);
                        } else if (payload.subscription.type === 'channel.channel_points_automatic_reward_redemption.add') {
                            handleAutomaticReward(payload.event);
                        } else if (payload.subscription.type === 'channel.channel_points_custom_reward_redemption.add') {
                            handleCustomReward(payload.event);
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
                    // Only show "Connected to chat" if there are no messages loaded
                    const hasMessages = overlay.querySelector('.chat-message, .reward-message');
                    if (!hasMessages) {
                        overlay.innerHTML = '<p style="color: #999; text-align: center;">Connected to chat</p>';
                    }
                } else {
                    updateStatus(false, 'Subscription Failed');
                }
            }
            // Chat message handling
            function handleChatMessage(event) {
                // Check if message should be filtered
                if (isMessageFiltered(event)) {
                    return;
                }
                // Deduplicate: if a recent redemption from same user with identical text exists, skip showing this chat message
                const chatTextForMatch = extractTextFromEvent(event) || (event.message && event.message.text) || '';
                try {
                    if (consumeMatchingRedemption(event.chatter_user_login || event.chatter_user_id || null, event.chatter_user_name || event.chatter_user_display_name || null, chatTextForMatch)) {
                        console.log('Suppressed chat message because a matching recent redemption was recorded:', chatTextForMatch);
                        return;
                    }
                } catch (e) {
                    console.error('Error checking recent redemptions cache', e);
                }
                // Debug: Log badge data to console
                console.log('Badge data:', event.badges);
                const overlay = document.getElementById('chat-overlay');
                // Clear placeholder text
                if (overlay.children.length === 1 && overlay.children[0].tagName === 'P') {
                    overlay.innerHTML = '';
                }
                const messageDiv = document.createElement('div');
                messageDiv.className = 'chat-message';
                // Build badges HTML
                let badgesHtml = '';
                if (event.badges && event.badges.length > 0) {
                    badgesHtml = '<span class="chat-badges">';
                    event.badges.forEach(badge => {
                        // Look up badge URL from cache
                        const badgeUrl = badgeCache[badge.set_id]?.[badge.id];
                        if (badgeUrl) {
                            badgesHtml += `<img class="chat-badge" src="${badgeUrl}" alt="${badge.set_id}" title="${badge.set_id}">`;
                        }
                    });
                    badgesHtml += '</span>';
                }
                // Build message HTML with emotes support
                let messageHtml = event.message.text;
                if (event.message.fragments) {
                    messageHtml = '';
                    event.message.fragments.forEach(fragment => {
                        if (fragment.type === 'emote' && fragment.emote) {
                            messageHtml += `<img src="https://static-cdn.jtvnw.net/emoticons/v2/${fragment.emote.id}/default/dark/1.0" alt="${fragment.text}" title="${fragment.text}" style="vertical-align: middle;">`;
                        } else {
                            messageHtml += escapeHtml(fragment.text);
                        }
                    });
                }
                // Check if it's a shared chat message
                const isSharedChat = event.source_broadcaster_user_id !== null;
                const sharedChatIndicator = isSharedChat ? 
                    `<span class="shared-chat-indicator">[from ${event.source_broadcaster_user_name}]</span>` : '';
                messageDiv.innerHTML = `
                    ${badgesHtml}
                    <span class="chat-username" style="color: ${event.color || '#ffffff'}">${escapeHtml(event.chatter_user_name)}:</span>
                    <span class="chat-text">${messageHtml}</span>
                    ${sharedChatIndicator}
                `;
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
                const exitBtn = document.getElementById('fullscreen-exit');
                if (container.classList.contains('fullscreen-mode')) {
                    // Exit fullscreen
                    container.classList.remove('fullscreen-mode');
                    overlay.classList.remove('fullscreen');
                    if (exitBtn) {
                        exitBtn.style.display = 'none';
                    }
                    icon.textContent = '⛶';
                } else {
                    // Enter fullscreen
                    container.classList.add('fullscreen-mode');
                    overlay.classList.add('fullscreen');
                    if (exitBtn) {
                        exitBtn.style.display = 'block';
                        exitBtn.style.visibility = 'visible';
                    }
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
                // Record this redemption so a near-simultaneous chat message from the same user with the same text can be ignored
                try {
                    addRecentRedemption(event.user_login || event.user_id || null, event.user_name || null, redemptionText);
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
                // Record this redemption in cache so matching chat messages can be suppressed
                const redemptionText = extractTextFromEvent(event) || (event.user_input ? String(event.user_input).trim() : '');
                try {
                    addRecentRedemption(event.user_login || event.user_id || null, event.user_name || null, redemptionText);
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
            // Initialize
            loadChatHistory();
            renderFilters();
            fetchBadges(); // Fetch badge data
            connectWebSocket();
            updateTokenTimer();
            // Update token timer every second
            setInterval(updateTokenTimer, 1000);
        </script>
        <?php endif; ?>
    </div>
</body>
</html>