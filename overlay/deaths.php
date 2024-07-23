<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket Deaths Notifications</title>
    <script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
    <style>
        #deathOverlay {
            position: fixed;
            bottom: 0;
            left: 0;
            background-color: rgba(0, 0, 0, 0.8);
            color: #FFFFFF;
            padding: 10px;
            display: none;
        }
    </style>
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

            socket.on('NOTIFY', (data) => {
                console.log('Notification:', data);
                alert(data.message);
            });

            // Listen for TTS audio events
            socket.on('DEATHS', (data) => {
                console.log('Death:', data);
                const deathOverlay = document.createElement('div');
                deathOverlay.innerText = "Current Deaths in ${data.game}: ${data.death_text}";
                deathOverlay.style.display = "block";

                setTimeout(() => {
                    deathOverlay.style.display = 'none';
                }, 5000); // Display for 5 seconds
            });
        });
    </script>
</head>
<body>
    <div id="deathOverlay"></div>
</body>
</html>