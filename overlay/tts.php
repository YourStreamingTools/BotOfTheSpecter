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
    <title>WebSocket TTS Audio Notifications</title>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let socket;
            const retryInterval = 5000;
            let reconnectAttempts = 0;
            let currentAudio = null;
            const audioQueue = [];
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

            // Unlock audio context as early as possible (OBS browser source autoplay fix)
            function unlockAudio() {
                try {
                    const ctx = new (window.AudioContext || window.webkitAudioContext)();
                    const buf = ctx.createBuffer(1, 1, 22050);
                    const src = ctx.createBufferSource();
                    src.buffer = buf;
                    src.connect(ctx.destination);
                    src.start(0);
                    ctx.resume().then(() => ctx.close()).catch(() => {});
                } catch (e) {}
            }
            unlockAudio();
            document.addEventListener('click', unlockAudio, { capture: true });

            function enqueueAudio(url) {
                if (!url) return;
                audioQueue.push(url);
                if (!currentAudio) {
                    playNextAudio();
                }
            }

            function playNextAudio() {
                if (audioQueue.length === 0) {
                    currentAudio = null;
                    return;
                }                const url = audioQueue.shift();
                currentAudio = new Audio(`${url}?t=${new Date().getTime()}`);
                currentAudio.volume = 0.3;  // Reduced volume to 30%

                currentAudio.addEventListener('canplaythrough', () => {
                    console.log('Audio can play through without buffering');
                });

                currentAudio.addEventListener('ended', () => {
                    currentAudio = null;
                    playNextAudio();
                });

                currentAudio.addEventListener('error', (e) => {
                    console.error('Error occurred while loading the audio file:', e);
                    console.error('Audio source:', currentAudio.src);
                    console.error('Audio error code:', currentAudio.error ? currentAudio.error.code : 'Unknown');
                    console.error('Audio error message:', currentAudio.error ? currentAudio.error.message : 'Unknown');
                    // Try to fetch the URL to see if it's accessible
                    fetch(url, { method: 'HEAD' })
                        .then(response => {
                            console.log('File fetch status:', response.status);
                            console.log('Content-Type:', response.headers.get('content-type'));
                            if (!response.ok) {
                                console.error('File not accessible via HTTP:', response.status, response.statusText);
                            }
                        })
                        .catch(fetchError => {
                            console.error('Failed to fetch file:', fetchError);
                        });
                    currentAudio = null;
                    playNextAudio();
                });

                currentAudio.play().catch(error => {
                    console.warn('Autoplay blocked; audio will retry on next interaction:', error.name);
                });
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
                    socket.emit('REGISTER', { code: code, channel:'Overlay', name: 'TTS' });
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

                socket.on('NOTIFY', (data) => {
                    console.log('Notification:', data);
                });

                // Listen for TTS audio events
                socket.on('TTS', (data) => {
                    console.log('TTS event received:', data);
                    enqueueAudio(data.audio_file);
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

            // Handle user interaction to allow audio playback if blocked
            document.body.addEventListener('click', () => {
                if (currentAudio) {
                    currentAudio.play().catch(error => {
                        console.error('Error playing audio:', error);
                    });
                }
            }, { once: true });

            // Start initial connection
            connectWebSocket();
        });
    </script>
</head>
<body>
</body>
</html>