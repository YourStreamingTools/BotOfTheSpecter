<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket Walkon Audio Notifications</title>
    <link rel="stylesheet" href="index.css">
    <script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let socket;
            const retryInterval = 5000;
            let reconnectAttempts = 0;
            let currentAudio = null;
            const audioQueue = [];
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            if (!code) {
                alert('No code provided in the URL');
                return;
            }

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
                currentAudio = new Audio(`${url}?t=${new Date().getTime()}`);
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
                    alert('Failed to load audio file');
                    currentAudio = null;
                    playNextAudio();
                });

                currentAudio.play().catch(error => {
                    console.error('Error playing audio:', error);
                    alert('Click to play audio');
                });
            }

            function connectWebSocket() {
                socket = io('wss://websocket.botofthespecter.com', {
                    reconnection: false
                });

                socket.on('connect', () => {
                    console.log('Connected to WebSocket server');
                    reconnectAttempts = 0;
                    socket.emit('REGISTER', { code: code, channel:'Overlay', name: 'Walkons' });
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

                // Listen for WALKON events
                socket.on('WALKON', (data) => {
                    console.log('Walkon:', data);
                    const audioFile = `https://walkons.botofthespecter.com/${data.channel}/${data.user}.mp3`;
                    enqueueAudio(audioFile);
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