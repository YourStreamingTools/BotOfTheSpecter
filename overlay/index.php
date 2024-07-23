<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket Notifications & Overlay System for BotOfTheSpecter</title>
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

            socket.on('NOTIFY', (data) => {
                console.log('Notification:', data);
                alert(data.message);
            });

            socket.on('TTS', (data) => {
                console.log('TTS Audio file path:', data.audio_file);
                const audio = new Audio(data.audio_file);
                audio.volume = 0.8;
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
                }, 100);
            });

            socket.on('WALKON', (data) => {
                console.log('Walkon:', data);
                const audioFile = `https://walkons.botofthespecter.com/${data.channel}/${data.user}.mp3`;
                const audio = new Audio(audioFile);
                audio.volume = 0.8;
                audio.autoplay = true;
                audio.addEventListener('canplaythrough', () => {
                    console.log('Walkon audio can play through without buffering');
                });
                audio.addEventListener('error', (e) => {
                    console.error('Error occurred while loading the Walkon audio file:', e);
                    alert('Failed to load Walkon audio file');
                });

                setTimeout(() => {
                    audio.play().catch(error => {
                        console.error('Error playing Walkon audio:', error);
                        alert('Click to play Walkon audio');
                    });
                }, 100);
            });

            socket.on('DEATHS', (data) => {
                console.log('Death:', data);
                const deathOverlay = document.getElementById('deathOverlay');
                deathOverlay.innerHTML = `
                    <div class="overlay-content">
                        <div class="overlay-title">Current Deaths</div>
                        <div>${data.game}</div>
                        <div>${data['death-text']}</div>
                    </div>
                `;
                deathOverlay.classList.add('show');

                setTimeout(() => {
                    deathOverlay.classList.remove('show');
                    deathOverlay.classList.add('hide');
                }, 10000); // Display for 10 seconds

                setTimeout(() => {
                    deathOverlay.classList.remove('hide');
                    deathOverlay.style.display = 'none';
                }, 10000); // Allow animation to complete
            });
        });
    </script>
</head>
<body>
    <div id="deathOverlay"></div>
</body>
</html>