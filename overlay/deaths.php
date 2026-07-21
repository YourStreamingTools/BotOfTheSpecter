<?php
include '/var/www/config/database.php';
$primary_db_name = 'website';
$conn = new mysqli($db_servername, $db_username, $db_password, $primary_db_name);
$api_key = $_GET['code'] ?? '';
$username = '';
if (!empty($api_key) && !$conn->connect_error) {
    $stmt = $conn->prepare("SELECT username FROM users WHERE api_key = ?");
    if ($stmt) {
        $stmt->bind_param("s", $api_key);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $username = $user['username'] ?? '';
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket Deaths Notifications</title>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let socket;
            const retryInterval = 5000;
            let reconnectAttempts = 0;
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            const username = <?php echo json_encode($username); ?>;
            function showOverlayError(message, type) {
                let banner = document.getElementById('overlayErrorBanner');
                if (!banner) {
                    banner = document.createElement('div');
                    banner.id = 'overlayErrorBanner';
                    document.body.appendChild(banner);
                }
                banner.textContent = message;
                banner.className = 'overlay-error-banner ' + (type === 'warn' ? 'overlay-error-banner-warn' : 'overlay-error-banner-danger');
                banner.style.display = 'block';
                if (type === 'warn') {
                    clearTimeout(banner._timeoutId);
                    banner._timeoutId = setTimeout(() => { banner.style.display = 'none'; }, 6000);
                }
            }
            function setConnectionStatus(text, state) {
                let status = document.getElementById('overlayConnectionStatus');
                if (!status) {
                    status = document.createElement('div');
                    status.id = 'overlayConnectionStatus';
                    status.className = 'overlay-connection-status';
                    document.body.appendChild(status);
                }
                status.textContent = text;
                status.dataset.state = state;
            }
            if (!code) {
                showOverlayError('No code provided in the URL', 'danger');
                return;
            }
            if (!username) {
                showOverlayError('Invalid code provided in the URL', 'danger');
                return;
            }

            function escapeHtml(str) {
                return String(str ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function connectWebSocket() {
                setConnectionStatus('Connecting…', 'connecting');
                socket = io('wss://websocket.botofthespecter.com', {
                    reconnection: false
                });

                socket.on('connect', () => {
                    console.log('Connected to WebSocket server');
                    setConnectionStatus('Connected', 'connected');
                    reconnectAttempts = 0;
                    socket.emit('REGISTER', { code: code, channel:'Overlay', name: 'Deaths' });
                });

                socket.on('disconnect', () => {
                    console.log('Disconnected from WebSocket server');
                    setConnectionStatus('Disconnected', 'error');
                    attemptReconnect();
                });

                socket.on('connect_error', (error) => {
                    console.error('Connection error:', error);
                    setConnectionStatus('Connection error', 'error');
                    attemptReconnect();
                });

                socket.on('WELCOME', (data) => {
                    console.log('Server says:', data.message);
                });

                socket.on('DEATHS', (data) => {
                    console.log('DEATHS event received:', data);
                    const deathOverlay = document.getElementById('deathOverlay');
                    deathOverlay.innerHTML = `
                        <div class="deaths-overlay-page-content">
                            <div class="deaths-overlay-page-title">
                                <span class="deaths-overlay-page-emote"></span>
                                <span>Current Deaths</span>
                            </div>
                            <div>${escapeHtml(data.game)}</div>
                            <div>${escapeHtml(data['death-text'])}</div>
                        </div>
                    `;
                    deathOverlay.classList.remove('hide');
                    deathOverlay.classList.add('show');
                    deathOverlay.style.display = 'block';

                    setTimeout(() => {
                        deathOverlay.classList.remove('show');
                        deathOverlay.classList.add('hide');
                    }, 10000);

                    setTimeout(() => {
                        deathOverlay.style.display = 'none';
                    }, 11000);
                });

                // Dashboard "Refresh Overlay" - full page reload so PHP re-fetches settings.
                socket.on('OVERLAY_REFRESH', (data) => {
                    console.log('OVERLAY_REFRESH received - reloading', data);
                    const meta = document.createElement('meta');
                    meta.setAttribute('http-equiv', 'refresh');
                    meta.setAttribute('content', '0');
                    document.head.appendChild(meta);
                });

                // Log all events
                socket.onAny((event, ...args) => {
                    if (event.startsWith('CLOSED_CAPTION')) return;
                    console.log(`[onAny] Event: ${event}`, ...args);
                });
            }

            function attemptReconnect() {
                reconnectAttempts++;
                const delay = Math.min(retryInterval * reconnectAttempts, 30000);
                console.log(`Attempting to reconnect in ${delay / 1000} seconds...`);
                setConnectionStatus('Reconnecting…', 'connecting');
                setTimeout(() => {
                    connectWebSocket();
                }, delay);
            }

            // Start initial connection
            connectWebSocket();
        });
    </script>
</head>
<body>
    <div id="deathOverlay" class="deaths-overlay-page"></div>
</body>
</html>