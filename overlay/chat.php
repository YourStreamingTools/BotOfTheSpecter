<?php
require_once "/var/www/config/database.php";

// Load yourchat settings for this API key so filters/nicknames work without a login
$code = isset($_GET['code']) ? trim($_GET['code']) : '';
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

$settingsJson = json_encode(
    $injectedSettings,
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
            font-size: 15px;
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
        // Badge rendering
        const TWITCH_BADGE_CDN = 'https://static-cdn.jtvnw.net/badges/v1/';
        const KNOWN_BADGE_IDS = {
            'broadcaster': '5527c58c-fb7d-422d-b71b-f309dcb85cc1',
            'moderator':   '3267646d-33f0-4b17-b3df-f923a41db1d0',
            'subscriber':  '5d9f2208-5dd8-11e7-8513-2ff4adfae661',
            'vip':         'b817aba4-fad8-49e2-b88a-7cc744dfa6ec',
            'staff':       'd97c37be-a963-4a4e-9b73-3a1fc1a0a5ec',
            'admin':       '9ef7e029-4cdf-4d4d-a0d5-e2b3fb2583fe',
            'global_mod':  '9384c37d-8d78-4225-8f73-bf3c5cb80d37',
            'partner':     'd12a2e27-16f6-41d0-ab77-b780518f00a3',
            'premium':     'a1dd5073-19c3-4911-8cb4-c464a7bc1510',
            'bits':        '09d93036-e7ce-431c-9a9e-7044297133f2',
            'turbo':       'bd444ec6-8f34-4bf9-91f4-af1e3428d80f',
        };
        function parseBadges(badgeStr) {
            if (!badgeStr) return [];
            return badgeStr.split(',').map(b => {
                const [name, version] = b.split('/');
                return { name: name || '', version: version || '1' };
            }).filter(b => b.name);
        }
        function buildBadgeHtml(badgeStr) {
            return parseBadges(badgeStr).map(b => {
                const id = KNOWN_BADGE_IDS[b.name];
                if (!id) return '';
                return `<img class="badge-img" src="${TWITCH_BADGE_CDN}${id}/1" alt="${b.name}" title="${b.name}" onerror="this.style.display='none'">`;
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
        function addMessage(data) {
            // Apply username / phrase filters shared with yourchat
            if (isMessageFiltered(data.username, data.display_name, data.message)) return;
            // Use custom nickname if the streamer has set one for this user
            const nickname = getNickname(data.user_id);
            const displayName = nickname || data.display_name || data.username || 'Unknown';
            const color = adjustColorForReadability(data.color);
            const badgeHtml = buildBadgeHtml(data.badges || '');
            const msgHtml   = buildMessageHtml(data.message || '', data.emotes || '');
            const el = document.createElement('div');
            el.className = 'chat-message';
            el.dataset.msgId = data.message_id || '';
            el.innerHTML = `
                <div class="msg-inner">
                    <span class="msg-badges">${badgeHtml}</span><span class="msg-author" style="color:${escapeHtml(color)}">${escapeHtml(displayName)}:</span>
                    <span class="msg-text">${msgHtml}</span>
                </div>`;
            container.appendChild(el);
            enforceMax();
        }
        function enforceMax() {
            while (container.children.length > maxMessages) {
                const oldest = container.children[0];
                oldest.classList.add('removing');
                oldest.addEventListener('animationend', () => oldest.remove(), { once: true });
                setTimeout(() => { if (oldest.parentNode) oldest.remove(); }, 400);
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
        // WebSocket connection
        function connectWebSocket() {
            socket = io('wss://websocket.botofthespecter.com', { reconnection: false });
            socket.on('connect', () => {
                reconnectAttempts = 0;
                socket.emit('REGISTER', { code: code, channel: 'Overlay', name: 'Chat Overlay ' + count });
            });
            socket.on('disconnect',    () => attemptReconnect());
            socket.on('connect_error', () => attemptReconnect());
            socket.on('CHAT_MESSAGE', data => addMessage(data));
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