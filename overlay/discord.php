<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Discord Join Notifications</title>
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
                    socket.emit('REGISTER', { code: code, channel:'Overlay', name: 'Discord Join' });
                });

                socket.on('disconnect', () => {
                    console.log('Disconnected from WebSocket server');
                    attemptReconnect();
                });

                socket.on('connect_error', (error) => {
                    console.error('Connection error:', error);
                    attemptReconnect();
                });

                // Listen for DISCORD_JOIN events
                socket.on('DISCORD_JOIN', (data) => {
                    console.log('DISCORD_JOIN event received:', data);
                    const discordOverlay = document.getElementById('discordOverlay');
                    discordOverlay.innerHTML = `
                        <div class="overlay-content">
                            <span>
                                <img src="https://cdn.jsdelivr.net/npm/simple-icons@v6/icons/discord.svg" alt="Discord Icon" class="discord-icon"> 
                                ${data.member} has joined the Discord server
                            </span>
                        </div>
                    `;
                    discordOverlay.classList.add('show');
                    discordOverlay.style.display = 'block';

                    // Display for 5 seconds
                    setTimeout(() => {
                        discordOverlay.classList.remove('show');
                        discordOverlay.classList.add('hide');
                    }, 10000);

                    // Hide after the transition
                    setTimeout(() => {
                        discordOverlay.style.display = 'none';
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
    <div id="discordOverlay" class="discord-overlay"></div>
</body>
</html>