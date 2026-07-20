<?php
include '/var/www/config/database.php';
$primary_db_name = 'website';
$conn = new mysqli($db_servername, $db_username, $db_password, $primary_db_name);
$api_key = $_GET['code'] ?? '';
if ($api_key === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $api_key)) { http_response_code(400); exit; }
if ($conn->connect_error) { http_response_code(500); exit; }
$username = '';
$stmt = $conn->prepare("SELECT username FROM users WHERE api_key = ?");
if ($stmt) {
    $stmt->bind_param("s", $api_key);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $username = $user['username'] ?? '';
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Media Player Overlay</title>
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <style>
        html,body{margin:0;background:transparent;overflow:hidden}
        #player{width:320px;height:180px}
        #gesture{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;
                 background:rgba(0,0,0,.85);color:#fff;font:bold 1.4em sans-serif;cursor:pointer;z-index:9999}
        #nowplaying{position:fixed;bottom:8px;left:8px;color:#fff;font:bold 18px sans-serif;
                    text-shadow:2px 2px 4px rgba(0,0,0,.8);display:none}
    </style>
</head>
<body>
    <div id="player"></div>
    <div id="nowplaying"></div>
    <div id="gesture">Click to enable song-request playback</div>
    <script>
        const params = new URLSearchParams(location.search);
        const code = params.get('code');
        const showNP = params.has('nowplaying');
        let player = null, ready = false, started = false, volume = 30, pendingVideo = null;
        let currentVideoId = null;
        let socket = null, reconnectTimer = null;
        const npDiv = document.getElementById('nowplaying');

        // 1) YouTube IFrame API
        const tag = document.createElement('script');
        tag.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(tag);
        window.onYouTubeIframeAPIReady = function () {
            player = new YT.Player('player', {
                height: '180', width: '320',
                playerVars: { autoplay: 1, controls: 0, disablekb: 1, fs: 0, modestbranding: 1, rel: 0 },
                events: {
                    onReady: () => { ready = true; player.setVolume(volume); if (pendingVideo) loadVideo(pendingVideo); },
                    onStateChange: (e) => { if (e.data === YT.PlayerState.ENDED) emitEnded(); },
                    onError: () => emitEnded(),  // dead video -> advance, don't strand the queue
                }
            });
        };

        function loadVideo(v) {
            pendingVideo = v;
            if (!ready || !started) return;
            currentVideoId = v.video_id;
            player.loadVideoById(v.video_id);
            player.setVolume(volume);
            if (showNP) { npDiv.style.display = 'block'; npDiv.textContent = 'Now Playing: ' + (v.title || '') + (v.requested_by ? ' (req by ' + v.requested_by + ')' : ''); }
        }
        function emitEnded() {
            if (socket && currentVideoId) socket.emit('MEDIA_COMMAND', { command: 'ended', code: code, video_id: currentVideoId });
        }

        // 2) Gesture unlock (OBS "Interact" once) for autoplay-with-sound
        document.getElementById('gesture').addEventListener('click', function () {
            started = true;
            this.style.display = 'none';
            if (ready && pendingVideo) loadVideo(pendingVideo);
        });

        // 3) WebSocket
        function scheduleReconnect() {
            if (reconnectTimer !== null) return;
            reconnectTimer = setTimeout(() => { reconnectTimer = null; connect(); }, 5000);
        }
        function connect() {
            socket = io('wss://websocket.botofthespecter.com', { reconnection: false });
            socket.on('connect', () => {
                if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
                if (!code) return;
                socket.emit('REGISTER', { code: code, channel: 'Overlay', name: 'Media Player' });
            });
            socket.on('SUCCESS', () => socket.emit('MEDIA_COMMAND', { command: 'request_state', code: code }));
            socket.on('MEDIA_PLAY', (v) => loadVideo(v));
            socket.on('MEDIA_STOP', () => { if (player && ready) player.stopVideo(); currentVideoId = null; if (showNP) npDiv.style.display = 'none'; });
            socket.on('MEDIA_VOLUME', (d) => { volume = Math.max(0, Math.min(100, Number(d.value))); if (ready) player.setVolume(volume); });
            socket.on('disconnect', scheduleReconnect);
            socket.on('connect_error', scheduleReconnect);
            // Dashboard "Refresh Overlay" - full page reload so PHP re-fetches settings.
            socket.on('OVERLAY_REFRESH', (data) => {
                console.log('OVERLAY_REFRESH received - reloading', data);
                const meta = document.createElement('meta');
                meta.setAttribute('http-equiv', 'refresh');
                meta.setAttribute('content', '0');
                document.head.appendChild(meta);
            });
        }
        if (code) connect();
    </script>
</body>
</html>
