<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Discord Join Notifications</title>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let socket;
            const retryInterval = 5000;
            let reconnectAttempts = 0;

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

            function escapeHtml(str) {
                return String(str ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            const discordQueue = [];
            let discordShowing = false;

            function enqueueDiscordJoin(member) {
                discordQueue.push(member);
                if (!discordShowing) {
                    showNextDiscordJoin();
                }
            }

            function showNextDiscordJoin() {
                if (discordQueue.length === 0) {
                    discordShowing = false;
                    return;
                }
                discordShowing = true;
                const member = discordQueue.shift();
                const discordOverlay = document.getElementById('discordOverlay');
                discordOverlay.innerHTML = `
                    <div class="discord-overlay-page-content">
                        <span>
                            <img src="https://cdn.jsdelivr.net/npm/simple-icons@v6/icons/discord.svg" alt="Discord Icon" class="discord-overlay-page-icon">
                            ${escapeHtml(member)} has joined the Discord server
                        </span>
                    </div>
                `;
                discordOverlay.classList.remove('hide');
                discordOverlay.classList.add('show');
                discordOverlay.style.display = 'block';

                // 10s visible, then start hide transition; 11s total before next event
                // is allowed to render. Matches all.php master overlay timing.
                setTimeout(() => {
                    discordOverlay.classList.remove('show');
                    discordOverlay.classList.add('hide');
                }, 10000);

                setTimeout(() => {
                    discordOverlay.style.display = 'none';
                    showNextDiscordJoin();
                }, 11000);
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
                    enqueueDiscordJoin(data.member);
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
    <div id="discordOverlay" class="discord-overlay-page"></div>
</body>
</html>