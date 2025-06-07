<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket Video Alert Notifications</title>
    <link rel="stylesheet" href="index.css">
    <style>
        .centered-video {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            max-width: 100%;
            max-height: 100%;
        }
    </style>
    <script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let socket;
            const retryInterval = 5000;
            let reconnectAttempts = 0;
            let currentVideo = null;
            const videoQueue = [];
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');

            if (!code) {
                alert('No code provided in the URL');
                return;
            }

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
                currentVideo.className = 'centered-video';
                currentVideo.preload = 'auto';
                document.body.appendChild(currentVideo);
                currentVideo.addEventListener('canplaythrough', () => {
                    console.log('Video can play through without buffering');
                    currentVideo.play().catch(error => {
                        console.error('Error playing video:', error);
                        alert('Click to play video');
                    });
                });

                currentVideo.addEventListener('ended', () => {
                    document.body.removeChild(currentVideo);
                    currentVideo = null;
                    playNextVideo();
                });

                currentVideo.addEventListener('error', (e) => {
                    console.error('Error occurred while loading the video file:', e);
                    alert('Failed to load video file');
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
                    alert(data.message);
                });

                // Listen for VIDEO_ALERT events
                socket.on('VIDEO_ALERT', (data) => {
                    console.log('VIDEO_ALERT event received:', data);
                    enqueueVideo(data.video);
                });

                // Log all events
                socket.onAny((event, ...args) => {
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