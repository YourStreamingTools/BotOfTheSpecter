<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Discord Join Notifications</title>
    <link rel="stylesheet" href="index.css">
    <script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const socket = io('wss://websocket.botofthespecter.com:8080');
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            if (!code) {
                alert('No code provided in the URL');
                return;
            }

            socket.on('connect', () => {
                console.log('Connected to WebSocket server');
                socket.emit('REGISTER', { code: code });
            });

            socket.on('disconnect', () => {
                console.log('Disconnected from WebSocket server');
            });

            // Listen for DISCORD_JOIN events
            socket.on('DISCORD_JOIN', (data) => {
                console.log('Discord Join:', data);
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

                // Display for 10 seconds
                setTimeout(() => {
                    discordOverlay.classList.remove('show');
                    discordOverlay.classList.add('hide');
                }, 10000);

                // Hide after the transition
                setTimeout(() => {
                    discordOverlay.style.display = 'none';
                }, 11000);
            });
        });
    </script>
</head>
<body>
    <div id="discordOverlay" class="discord-overlay"></div>
</body>
</html>