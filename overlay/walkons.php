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
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            if (!code) {
                alert('No code provided in the URL');
                return;
            }

            function connectWebSocket() {
                socket = io('wss://websocket.botofthespecter.com', {
                    reconnection: false
                });

                socket.on('connect', () => {
                    console.log('Connected to WebSocket server');
                    reconnectAttempts = 0;
                    socket.emit('REGISTER', { code: code, name: 'Walkons Overlay' });
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
                    const audio = new Audio(audioFile);
                    audio.volume = 0.8;
                    audio.autoplay = true;
                    audio.addEventListener('canplaythrough', () => {
                        console.log('Walkon audio can play through without buffering');
                    });
                    audio.addEventListener('error', (e) => {
                        console.error('Error occurred while loading the Walkon audio file:', e);
                        alert('Failed to load Walkon audio file');
                    });

                    setTimeout(() => {
                        audio.play().catch(error => {
                            console.error('Error playing Walkon audio:', error);
                            alert('Click to play Walkon audio');
                        });
                    }, 100); // 100ms delay
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

            // Start initial connection
            connectWebSocket();
        });
    </script>
</head>
<body>
</body>
</html>