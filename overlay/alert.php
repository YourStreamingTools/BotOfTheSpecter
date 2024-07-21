<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket Notifications</title>
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

            socket.on('NOTIFY', (data) => {
                console.log('Notification:', data);
                alert(data.message);
            });

            // Listen for TTS audio events
            socket.on('TTS', (data) => {
                console.log('TTS Audio file path:', data.audio_file);
                const audio = new Audio(data.audio_file);
                audio.autoplay = true;
                audio.addEventListener('canplaythrough', () => {
                    console.log('Audio can play through without buffering');
                });
                audio.addEventListener('error', (e) => {
                    console.error('Error occurred while loading the audio file:', e);
                    alert('Failed to load audio file');
                });

                setTimeout(() => {
                    audio.play().catch(error => {
                        console.error('Error playing audio:', error);
                        alert('Click to play audio');
                    });
                }, 100); // 100ms delay
            });

            // Listen for WALKON events
            socket.on('WALKON', (data) => {
                console.log('Walkon:', data);
                alert(`Walkon: ${data}`);
            });
        });
    </script>
</head>
<body>
</body>
</html>