<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket Notifications</title>
    <script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const socket = io('wss://websocket.botofthespecter.com:8080');
            // Extract the code from the URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            if (!code) {
                alert('No code provided in the URL');
                return;
            }

            socket.on('connect', () => {
                console.log('Connected to WebSocket server');
                // Register the client with the extracted code
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
                // Handle the notification (e.g., display it to the user)
                alert(data.message);
            });

            // Listen for TTS audio events
            socket.on('TTS_AUDIO', (data) => {
                console.log('TTS Audio file path:', data.audio_file);
                // Play the audio file if needed
                const audio = new Audio(data.audio_file);
                audio.play();
            });
        });
    </script>
</head>
<body>
</body>
</html>