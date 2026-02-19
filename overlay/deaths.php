<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket Deaths Notifications</title>
    <link rel="stylesheet" href="index.css">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
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
                    socket.emit('REGISTER', { code: code, channel:'Overlay', name: 'Deaths' });
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

                socket.on('DEATHS', (data) => {
                    console.log('DEATHS event received:', data);
                    const deathOverlay = document.getElementById('deathOverlay');
                    deathOverlay.innerHTML = `
                        <div class="overlay-content">
                            <div class="overlay-title">
                                <span class="overlay-emote"></span>
                                <span>Current Deaths</span>
                            </div>
                            <div>${data.game}</div>
                            <div>${data['death-text']}</div>
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

            // Start initial connection
            connectWebSocket();
        });
    </script>
</head>
<body>
    <div id="deathOverlay"></div>
</body>
</html>