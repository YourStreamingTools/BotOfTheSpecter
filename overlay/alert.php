<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket Notifications</title>
    <script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const socket = io('wss://websocket.botofthespecter.com:8080');

            socket.on('connect', () => {
                console.log('Connected to WebSocket server');
                // Register the client with a unique code if needed
                socket.emit('REGISTER', { code: 'your-unique-client-code' });
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