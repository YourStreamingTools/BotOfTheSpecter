<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket Video Alert Notifications</title>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let socket;
            const retryInterval = 5000;
            let reconnectAttempts = 0;
            let currentVideo = null;
            const videoQueue = [];
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');

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

            if (!code) {
                showOverlayError('No code provided in the URL', 'danger');
                return;
            }

            // Unlock audio/video context as early as possible (OBS browser source autoplay fix)
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

            function enqueueVideo(url) {
                if (!url) return;
                videoQueue.push(url);
                if (!currentVideo) {
                    playNextVideo();
                }
            }

            function playNextVideo() {
                if (videoQueue.length === 0) {
                    currentVideo = null;
                    return;
                }

                const url = videoQueue.shift();
                currentVideo = document.createElement('video');
                currentVideo.src = `${url}?t=${new Date().getTime()}`;
                currentVideo.volume = 0.8;
                currentVideo.controls = false;
                currentVideo.className = 'video-alert-overlay-page-video';
                currentVideo.preload = 'auto';
                document.body.appendChild(currentVideo);
                currentVideo.addEventListener('canplaythrough', () => {
                    console.log('Video can play through without buffering');
                    currentVideo.play().catch(error => {
                        console.warn('Autoplay blocked; video will retry on next interaction:', error.name);
                    });
                });

                currentVideo.addEventListener('ended', () => {
                    document.body.removeChild(currentVideo);
                    currentVideo = null;
                    playNextVideo();
                });

                currentVideo.addEventListener('error', (e) => {
                    console.error('Error occurred while loading the video file:', e);
                    document.body.removeChild(currentVideo);
                    currentVideo = null;
                    playNextVideo();
                });
            }

            function connectWebSocket() {
                socket = io('wss://websocket.botofthespecter.com', {
                    reconnection: false
                });

                socket.on('connect', () => {
                    console.log('Connected to WebSocket server');
                    reconnectAttempts = 0;
                    socket.emit('REGISTER', { code: code, channel:'Overlay', name: 'Video Alerts' });
                });

                socket.on('disconnect', () => {
                    console.log('Disconnected from WebSocket server');
                    attemptReconnect();
                });

                socket.on('connect_error', (error) => {
                    console.error('Connection error:', error);
                    attemptReconnect();
                });

                socket.on('WELCOME', (data) => {
                    console.log('Server says:', data.message);
                });

                socket.on('NOTIFY', (data) => {
                    console.log('Notification:', data);
                });

                // Listen for VIDEO_ALERT events
                socket.on('VIDEO_ALERT', (data) => {
                    console.log('VIDEO_ALERT event received:', data);
                    enqueueVideo(data.video);
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
                setTimeout(() => {
                    connectWebSocket();
                }, delay);
            }

            // Handle user interaction to allow video playback if blocked
            document.body.addEventListener('click', () => {
                if (currentVideo) {
                    currentVideo.play().catch(error => {
                        console.error('Error playing video:', error);
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