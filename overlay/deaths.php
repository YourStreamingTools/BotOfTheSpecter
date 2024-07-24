<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket Deaths Notifications</title>
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

            socket.on('WELCOME', (data) => {
                console.log('Server says:', data.message);
            });

            socket.on('DEATHS', (data) => {
                console.log('Death:', data);
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
        });
    </script>
</head>
<body>
    <div id="deathOverlay"></div>
</body>
</html>