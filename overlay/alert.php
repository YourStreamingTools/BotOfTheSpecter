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
    <title>WebSocket Audio Notifications</title>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <script src="js/specter-ws.js?v=<?php echo filemtime(__DIR__ . '/js/specter-ws.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
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

            // Handle user interaction to allow audio playback if blocked
            document.body.addEventListener('click', () => {
                if (currentAudio) {
                    currentAudio.play().catch(error => {
                        console.error('Error playing audio:', error);
                    });
                }
            }, { once: true });

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
                }

                const url = audioQueue.shift();
                currentAudio = new Audio(SpecterOverlayWS.playbackUrl(url));
                currentAudio.volume = 0.8;

                currentAudio.addEventListener('canplaythrough', () => {
                    console.log('Audio can play through without buffering');
                });

                currentAudio.addEventListener('ended', () => {
                    currentAudio = null;
                    playNextAudio();
                });

                currentAudio.addEventListener('error', (e) => {
                    console.error('Error occurred while loading the audio file:', e);
                    currentAudio = null;
                    playNextAudio();
                });

                currentAudio.play().catch(error => {
                    console.warn('Autoplay blocked; audio will retry on next interaction:', error.name);
                });
            }

            // Specter bus: helper owns reconnect (reconnection:false + progressive backoff)
            const session = SpecterOverlayWS.create({
                code: code,
                channel: 'Overlay',
                name: 'All Audio',
                onStatus: (text, state) => setConnectionStatus(text, state),
                bind: (socket) => {
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

                    // Listen for WALKON events
                    socket.on('WALKON', (data) => {
                        console.log('WALKON event received:', data);
                        // Migrated channels send media_file; legacy send user (+ optional ext)
                        let audioFile;
                        if (data.media_file) {
                            audioFile = `https://media.botofthespecter.com/${encodeURIComponent(data.channel)}/${encodeURIComponent(data.media_file)}`;
                        } else {
                            const ext = data.ext && data.ext.startsWith('.') ? data.ext : '.mp3';
                            audioFile = `https://walkons.botofthespecter.com/${encodeURIComponent(data.channel)}/${encodeURIComponent(data.user)}${ext}`;
                        }
                        enqueueAudio(audioFile);
                    });

                    // Listen for SOUND_ALERT audio events
                    socket.on('SOUND_ALERT', (data) => {
                        console.log('SOUND_ALERT event received:', data);
                        enqueueAudio(data.sound);
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
            });

            session.connect();
        });
    </script>
</head>
<body>
</body>
</html>