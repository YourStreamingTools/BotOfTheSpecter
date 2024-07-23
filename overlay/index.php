<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket Notifications & Overlay System for BotOfTheSpecter</title>
    <style>
        @keyframes swingIn {
            0% {
                transform: translateX(-100%) rotate(0);
                opacity: 0;
            }
            50% {
                transform: translateX(0) rotate(0);
                opacity: 1;
            }
        }

        @keyframes swingOut {
            0% {
                transform: translateX(0) rotate(0);
                opacity: 1;
            }
            100% {
                transform: translateX(100%) rotate(0);
                opacity: 0;
            }
        }

        #deathOverlay {
            position: fixed;
            bottom: 0;
            left: 0;
            background-color: rgba(0, 0, 0, 0.8);
            color: #FFFFFF;
            padding: 20px;
            font-size: 40px;
            border-radius: 10px;
            display: none;
            animation-duration: 1s;
        }

        #deathOverlay.show {
            display: block;
            animation-name: swingIn;
        }

        #deathOverlay.hide {
            animation-name: swingOut;
        }
    </style>
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
                audio.volume = 0.8
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
                audio.volume = 0.8
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
                deathOverlay.innerText = `Current Deaths in ${data.game}: ${data['death-text']}`;
                deathOverlay.classList.add('show');

                setTimeout(() => {
                    deathOverlay.classList.remove('show');
                    deathOverlay.classList.add('hide');
                }, 10000); // Display for 10 seconds

                setTimeout(() => {
                    deathOverlay.classList.remove('hide');
                    deathOverlay.style.display = 'none';
                }, 11000); // Allow animation to complete
            });
        });
    </script>
</head>
<body>
    <div id="deathOverlay"></div>
</body>
</html>