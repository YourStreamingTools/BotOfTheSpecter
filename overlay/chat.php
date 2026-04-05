<?php
require_once "/var/www/config/database.php";

// Load yourchat settings for this API key so filters/nicknames work without a login
$code = isset($_GET['code']) ? trim($_GET['code']) : '';
$twitchUserId = '';
$injectedSettings = [
    'filters_usernames' => [],
    'filters_messages'  => [],
    'nicknames'         => (object)[],
];

if ($code !== '') {
    // Suppress connection error — overlay still works without settings
    $conn = @new mysqli($db_servername, $db_username, $db_password, 'website');
    if (!$conn->connect_error) {
        $stmt = $conn->prepare("SELECT twitch_user_id FROM users WHERE api_key = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                // Twitch user IDs are numeric — sanitise before using in a file path
                $twitchUserId = preg_replace('/[^0-9]/', '', (string)$row['twitch_user_id']);
                if ($twitchUserId !== '') {
                    $settingsFile = '/var/www/yourchat/user-settings/' . $twitchUserId . '.json';
                    if (is_readable($settingsFile)) {
                        $parsed = json_decode(file_get_contents($settingsFile), true);
                        if (is_array($parsed)) {
                            if (!empty($parsed['filters_usernames']) && is_array($parsed['filters_usernames'])) {
                                $injectedSettings['filters_usernames'] = array_values($parsed['filters_usernames']);
                            }
                            if (!empty($parsed['filters_messages']) && is_array($parsed['filters_messages'])) {
                                $injectedSettings['filters_messages'] = array_values($parsed['filters_messages']);
                            }
                            if (isset($parsed['nicknames']) && is_array($parsed['nicknames'])) {
                                $injectedSettings['nicknames'] = empty($parsed['nicknames'])
                                    ? (object)[]
                                    : (object)$parsed['nicknames'];
                            }
                        }
                    }
                }
            }
            $stmt->close();
        }
        $conn->close();
    }
}

// Fetch Twitch badge data (global + channel-specific) using the bot's API credentials.
// Results are cached for 1 hour in a JSON file to avoid hitting the API on every page load.
$overlayBadgeCache = [];
if ($twitchUserId !== '') {
    $badgeCacheFile = '/var/www/yourchat/chat-logs/' . $twitchUserId . '_badge_cache.json';
    $badgeNeedsFetch = true;
    if (is_readable($badgeCacheFile) && (time() - filemtime($badgeCacheFile)) < 3600) {
        $raw = file_get_contents($badgeCacheFile);
        $cacheParsed = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($cacheParsed)) {
            $overlayBadgeCache = $cacheParsed;
            $badgeNeedsFetch = false;
        }
    }
    if ($badgeNeedsFetch) {
        $botClientId = '';
        $botOauth    = '';
        $bconn = @new mysqli($db_servername, $db_username, $db_password, 'website');
        if (!$bconn->connect_error) {
            $bres = $bconn->query("SELECT * FROM bot_chat_token ORDER BY id ASC LIMIT 1");
            if ($bres) {
                $brow = $bres->fetch_assoc();
                if ($brow) {
                    foreach (['twitch_client_id', 'client_id', 'clientID'] as $k) {
                        if (!empty($brow[$k])) { $botClientId = trim($brow[$k]); break; }
                    }
                    foreach (['twitch_oauth_api_token', 'oauth', 'chat_oauth_token', 'twitch_oauth_token', 'twitch_access_token', 'bot_oauth_token'] as $k) {
                        if (!empty($brow[$k])) { $botOauth = trim($brow[$k]); break; }
                    }
                }
            }
            $bconn->close();
        }
        if ($botClientId !== '' && $botOauth !== '') {
            $ctx = stream_context_create(['http' => [
                'method'        => 'GET',
                'header'        => "Authorization: Bearer $botOauth\r\nClient-Id: $botClientId\r\n",
                'timeout'       => 5,
                'ignore_errors' => true,
            ]]);
            $cache = [];
            $badgeEndpoints = [
                'https://api.twitch.tv/helix/chat/badges/global',
                "https://api.twitch.tv/helix/chat/badges?broadcaster_id=$twitchUserId",
            ];
            foreach ($badgeEndpoints as $endpoint) {
                $raw = @file_get_contents($endpoint, false, $ctx);
                if (!$raw) continue;
                $data = json_decode($raw, true);
                if (!is_array($data['data'] ?? null)) continue;
                foreach ($data['data'] as $set) {
                    $setId = $set['set_id'];
                    if (!isset($cache[$setId])) $cache[$setId] = [];
                    foreach ($set['versions'] as $ver) {
                        $cache[$setId][$ver['id']] = $ver['image_url_1x'];
                    }
                }
            }
            $overlayBadgeCache = $cache;
            $logsDir = '/var/www/yourchat/chat-logs';
            if (!is_dir($logsDir)) mkdir($logsDir, 0755, true);
            @file_put_contents($badgeCacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE));
        }
    }
}

// Return current filters/nicknames so the overlay can refresh without a full reload
if ($twitchUserId !== '' && isset($_GET['action']) && $_GET['action'] === 'get_settings') {
    header('Content-Type: application/json');
    echo json_encode($injectedSettings, JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle overlay history save — called periodically by the overlay JS via POST
if ($twitchUserId !== '' && isset($_GET['action']) && $_GET['action'] === 'save_history' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $logsDir = '/var/www/yourchat/chat-logs';
    $historyFile = $logsDir . '/' . $twitchUserId . '_overlay_chat_history.json';
    $jsonInput = file_get_contents('php://input');
    $messages = json_decode($jsonInput, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($messages)) {
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }
        file_put_contents($historyFile, json_encode($messages, JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    }
    exit;
}

// Load today's overlay history to embed in the page (no client-side AJAX needed)
$overlayHistory = [];
if ($twitchUserId !== '') {
    $historyFile = '/var/www/yourchat/chat-logs/' . $twitchUserId . '_overlay_chat_history.json';
    if (is_readable($historyFile)) {
        // Discard history from a previous day (same behaviour as yourchat)
        if (date('Y-m-d', filemtime($historyFile)) === date('Y-m-d')) {
            $raw = file_get_contents($historyFile);
            $msgs = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($msgs)) {
                $overlayHistory = array_slice($msgs, -50); // at most 50 recent messages
            }
        }
    }
}

$settingsJson = json_encode(
    $injectedSettings,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);
$historyJson = json_encode(
    $overlayHistory,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);
$badgeCacheJson = json_encode(
    empty($overlayBadgeCache) ? (object)[] : $overlayBadgeCache,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Chat Overlay</title>
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: transparent;
            font-family: "Inter", "Segoe UI", system-ui, sans-serif;
            font-size: 16px;
            overflow: hidden;
            width: 100vw;
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
        }
        #chat-container {
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            gap: 4px;
            padding: 8px;
            overflow: hidden;
            max-height: 100vh;
        }
        .chat-message {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            animation: msgIn 0.2s ease-out forwards;
            max-width: 100%;
            word-break: break-word;
        }
        @keyframes msgIn {
            from { opacity: 0; transform: translateY(6px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .msg-inner {
            background: rgba(0, 0, 0, 0.55);
            border-radius: 6px;
            padding: 5px 8px;
            line-height: 1.4;
            max-width: 100%;
        }
        .msg-author {
            font-weight: 700;
            margin-right: 4px;
            white-space: nowrap;
        }
        .msg-badges {
            display: inline-flex;
            gap: 2px;
            vertical-align: middle;
            margin-right: 4px;
        }
        .msg-badges img {
            width: 18px;
            height: 18px;
            vertical-align: middle;
        }
        .msg-text {
            color: #ffffff;
            word-break: break-word;
        }
        .msg-emote {
            display: inline-block;
            vertical-align: middle;
            height: 28px;
            width: auto;
        }
        .chat-message.removing {
            animation: msgOut 0.3s ease-in forwards;
        }
        .chat-message.no-anim {
            animation: none;
        }
        @keyframes msgOut {
            from { opacity: 1; max-height: 200px; }
            to   { opacity: 0; max-height: 0; padding: 0; margin: 0; }
        }
    </style>
</head>
<body>
<div id="chat-container"></div>
<script>
    // Settings injected server-side from the user's yourchat configuration.
    // Filters and nicknames are shared between yourchat and this overlay.
    const OVERLAY_SETTINGS = <?php echo $settingsJson; ?>;
    const OVERLAY_HISTORY = <?php echo $historyJson; ?>;
    // Badge image URLs fetched from Twitch Helix API (global + channel-specific).
    // Structure: { set_id: { version_id: image_url } }
    const OVERLAY_BADGE_CACHE = <?php echo $badgeCacheJson; ?>;
</script>
<script>
    (function () {
        const params = new URLSearchParams(window.location.search);
        const code = params.get('code');
        const maxMessages = parseInt(params.get('max') || '20', 10);
        const count = parseInt(params.get('count') || '1', 10);
        if (!code) {
            document.body.innerHTML = '<p style="color:red;padding:10px;">Missing ?code= in URL</p>';
            return;
        }
        const container = document.getElementById('chat-container');
        let reconnectAttempts = 0;
        let socket;
        let messageBuffer = Array.isArray(OVERLAY_HISTORY) ? OVERLAY_HISTORY.slice() : [];
        async function saveHistory() {
            if (!code || messageBuffer.length === 0) return;
            try {
                await fetch('?action=save_history&code=' + encodeURIComponent(code), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(messageBuffer.slice(-100)),
                });
            } catch (_) { /* best-effort — overlay still works if save fails */ }
        }
        // Filtering
        // Returns true if the message should be hidden (matches a username or
        // phrase filter set by the streamer in yourchat).
        function isMessageFiltered(username, displayName, messageText) {
            const u = (username    || '').toLowerCase();
            const d = (displayName || '').toLowerCase();
            const m = (messageText || '').toLowerCase();
            const userFilters = OVERLAY_SETTINGS.filters_usernames || [];
            if (userFilters.some(f => {
                const fl = f.toLowerCase();
                return fl === u || fl === d;
            })) return true;
            const msgFilters = OVERLAY_SETTINGS.filters_messages || [];
            if (msgFilters.some(f => m.includes(f.toLowerCase()))) return true;
            return false;
        }
        // Nicknames
        // Returns the custom nickname for a Twitch user ID, or null if not set.
        function getNickname(userId) {
            const nicknames = OVERLAY_SETTINGS.nicknames || {};
            const entry = nicknames[userId];
            return (entry && entry.nickname) ? entry.nickname : null;
        }
        // Color readability
        // Lightens colours that would be hard to read on a dark overlay, and
        // converts absent / black colours to white.
        function adjustColorForReadability(color) {
            if (!color || color === '' || color === '#000000') return '#ffffff';
            const hex = color.replace('#', '');
            let r, g, b;
            if (hex.length === 3) {
                r = parseInt(hex[0] + hex[0], 16);
                g = parseInt(hex[1] + hex[1], 16);
                b = parseInt(hex[2] + hex[2], 16);
            } else if (hex.length === 6) {
                r = parseInt(hex.substring(0, 2), 16);
                g = parseInt(hex.substring(2, 4), 16);
                b = parseInt(hex.substring(4, 6), 16);
            } else {
                return '#ffffff';
            }
            const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
            if (luminance < 0.4) {
                const factor = 0.5 / luminance;
                r = Math.min(255, Math.floor(r * factor));
                g = Math.min(255, Math.floor(g * factor));
                b = Math.min(255, Math.floor(b * factor));
            }
            const toHex = n => { const h = Math.round(n).toString(16); return h.length === 1 ? '0' + h : h; };
            return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
        }
        // Badge rendering — uses the server-injected Helix API cache
        // (global badges + channel-specific subscriber/bits tiers for this streamer)
        function parseBadges(badgeStr) {
            if (!badgeStr) return [];
            return badgeStr.split(',').map(b => {
                const [name, version] = b.split('/');
                return { name: name || '', version: version || '1' };
            }).filter(b => b.name);
        }
        function buildBadgeHtml(badgeStr) {
            return parseBadges(badgeStr).map(b => {
                const url = (OVERLAY_BADGE_CACHE[b.name] || {})[b.version];
                if (!url) return '';
                return `<img class="badge-img" src="${url}" alt="${b.name}" title="${b.name}" onerror="this.style.display='none'">`;
            }).join('');
        }
        // Emote rendering
        // Parse "30259:0-6/425618:18-24" → map of charStart → {id, end}
        function parseEmotes(emotesStr) {
            if (!emotesStr) return {};
            const map = {};
            emotesStr.split('/').forEach(part => {
                const [id, ranges] = part.split(':');
                if (!id || !ranges) return;
                ranges.split(',').forEach(range => {
                    const [start, end] = range.split('-').map(Number);
                    map[start] = { id, end };
                });
            });
            return map;
        }
        function buildMessageHtml(text, emotesStr) {
            const emoteMap = parseEmotes(emotesStr);
            if (Object.keys(emoteMap).length === 0) return escapeHtml(text);
            let result = '';
            let i = 0;
            const chars = [...text]; // Unicode-safe split
            while (i < chars.length) {
                if (emoteMap[i]) {
                    const { id, end } = emoteMap[i];
                    const emoteName = chars.slice(i, end + 1).join('');
                    result += `<img class="msg-emote" src="https://static-cdn.jtvnw.net/emoticons/v2/${id}/default/dark/1.0" alt="${escapeHtml(emoteName)}" title="${escapeHtml(emoteName)}">`;
                    i = end + 1;
                } else {
                    result += escapeHtml(chars[i]);
                    i++;
                }
            }
            return result;
        }
        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }
        // Message rendering
        // isHistory = true: render without slide-in animation and skip buffering
        function addMessage(data, isHistory) {
            // Apply username / phrase filters shared with yourchat
            if (isMessageFiltered(data.username, data.display_name, data.message)) return;
            // Use custom nickname if the streamer has set one for this user
            const nickname = getNickname(data.user_id);
            const displayName = nickname || data.display_name || data.username || 'Unknown';
            const color = adjustColorForReadability(data.color);
            const badgeHtml = buildBadgeHtml(data.badges || '');
            const msgHtml   = buildMessageHtml(data.message || '', data.emotes || '');
            const el = document.createElement('div');
            el.className = 'chat-message' + (isHistory ? ' no-anim' : '');
            el.dataset.msgId = data.message_id || '';
            el.innerHTML = `
                <div class="msg-inner">
                    <span class="msg-badges">${badgeHtml}</span><span class="msg-author" style="color:${escapeHtml(color)}">${escapeHtml(displayName)}:</span>
                    <span class="msg-text">${msgHtml}</span>
                </div>`;
            container.appendChild(el);
            if (!isHistory && count <= 1) {
                // Track live messages for history persistence (only primary instance)
                messageBuffer.push(data);
                if (messageBuffer.length > 100) messageBuffer.shift();
            }
            enforceMax();
        }
        function enforceMax() {
            while (container.children.length > maxMessages) {
                container.children[0].remove();
            }
        }
        function removeMessage(msgId) {
            if (!msgId) return;
            container.querySelectorAll('.chat-message').forEach(el => {
                if (el.dataset.msgId === msgId) {
                    el.classList.add('removing');
                    el.addEventListener('animationend', () => el.remove(), { once: true });
                    setTimeout(() => { if (el.parentNode) el.remove(); }, 400);
                }
            });
        }
        function clearChat() {
            container.innerHTML = '';
        }
        // Render today's chat history before connecting so the overlay is populated immediately
        if (messageBuffer.length > 0) {
            messageBuffer.forEach(msg => addMessage(msg, true));
        }
        // Refresh filters & nicknames from the server every 60 s so changes
        // made in yourchat are reflected without reloading the overlay.
        async function refreshSettings() {
            try {
                const res = await fetch('?action=get_settings&code=' + encodeURIComponent(code));
                if (!res.ok) return;
                const data = await res.json();
                if (data.filters_usernames) OVERLAY_SETTINGS.filters_usernames = data.filters_usernames;
                if (data.filters_messages)  OVERLAY_SETTINGS.filters_messages  = data.filters_messages;
                if (data.nicknames)         OVERLAY_SETTINGS.nicknames         = data.nicknames;
            } catch (_) { /* best-effort */ }
        }
        setInterval(refreshSettings, 60000);
        // Save history to the server every 60 s (best-effort, keeps history fresh across reloads)
        // Only the first overlay instance (count=1) writes history; extras just read on load.
        if (count <= 1) {
            setInterval(saveHistory, 60000);
            // Flush the buffer on page unload (refresh / close) so history survives immediately
            window.addEventListener('beforeunload', () => {
                if (messageBuffer.length === 0) return;
                const url = '?action=save_history&code=' + encodeURIComponent(code);
                const body = JSON.stringify(messageBuffer.slice(-100));
                navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }));
            });
        }
        // WebSocket connection
        function connectWebSocket() {
            socket = io('wss://websocket.botofthespecter.com', { reconnection: false });
            socket.on('connect', () => {
                reconnectAttempts = 0;
                socket.emit('REGISTER', { code: code, channel: 'Overlay', name: 'Chat Overlay ' + count });
            });
            socket.on('disconnect',    () => attemptReconnect());
            socket.on('connect_error', () => attemptReconnect());
            socket.on('CHAT_MESSAGE', data => addMessage(data, false));
            socket.on('CHAT_CLEAR', () => clearChat());
            socket.on('CHAT_MESSAGE_DELETE', data => {
                if (data && data.message_id) removeMessage(data.message_id);
            });
        }
        function attemptReconnect() {
            reconnectAttempts++;
            const delay = Math.min(5000 * reconnectAttempts, 30000);
            setTimeout(connectWebSocket, delay);
        }
        connectWebSocket();
    })();
</script>
</body>
</html>