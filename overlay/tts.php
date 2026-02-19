<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket TTS Audio Notifications</title>
    <link rel="stylesheet" href="index.css">
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
                    alert('Failed to load audio file - check console for details');
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
                    socket.emit('REGISTER', { code: code, channel:'Overlay', name: 'TTS' });
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

                // Listen for TTS audio events
                socket.on('TTS', (data) => {
                    console.log('TTS event received:', data);
                    enqueueAudio(data.audio_file);
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