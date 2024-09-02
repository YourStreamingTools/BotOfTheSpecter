<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket Audio Notifications</title>
    <link rel="stylesheet" href="index.css">
    <script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const socket = io('wss://websocket.botofthespecter.com');
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

            // Function to play audio with error handling
            const playAudio = (audioFile) => {
                if (!audioFile) return;
                const audio = new Audio(audioFile);
                audio.volume = 0.8;
                audio.autoplay = true;

                audio.addEventListener('canplaythrough', () => {
                    console.log('Audio can play through without buffering');
                    audio.play().catch(error => {
                        console.error('Error playing audio:', error);
                        alert('Click to play audio');
                    });
                });

                audio.addEventListener('error', (e) => {
                    console.error('Error occurred while loading the audio file:', e);
                    alert('Failed to load audio file');
                });
            };

            // Listen for TTS audio events
            socket.on('TTS', (data) => {
                console.log('TTS Audio file path:', data.audio_file);
                playAudio(data.audio_file);
            });

            // Listen for WALKON events
            socket.on('WALKON', (data) => {
                console.log('Walkon:', data);
                const audioFile = `https://walkons.botofthespecter.com/${data.channel}/${data.user}.mp3`;
                playAudio(audioFile);
            });

            // Handle user interaction to allow audio playback if blocked
            document.body.addEventListener('click', () => {
                playAudio();  // Trigger playback on user interaction if needed
            }, { once: true });
        });
    </script>
</head>
<body>
</body>
</html>