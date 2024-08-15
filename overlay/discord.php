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
                        <img src="https://cdn.jsdelivr.net/npm/simple-icons@v6/icons/discord.svg" alt="Discord Icon" class="discord-icon">
                        <span>${data.member} has joined the Discord server</span>
                    </div>
                `;
                discordOverlay.classList.add('show');
                discordOverlay.style.display = 'block';

                setTimeout(() => {
                    discordOverlay.classList.remove('show');
                    discordOverlay.classList.add('hide');
                }, 10000);

                setTimeout(() => {
                    discordOverlay.style.display = 'none';
                }, 11000);
            });
        });
    </script>
    <style>
        /* Basic styling for the overlay */
        .discord-overlay {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background-color: rgba(0, 0, 0, 0.8);
            padding: 10px 20px;
            border-radius: 10px;
            color: white;
            font-size: 18px;
            display: none;
            z-index: 1000;
        }

        .discord-overlay .overlay-content {
            display: flex;
            align-items: center;
        }

        .discord-overlay .discord-icon {
            width: 30px;
            height: 30px;
            margin-right: 10px;
        }

        .discord-overlay.show {
            animation: fadeIn 0.5s forwards;
        }

        .discord-overlay.hide {
            animation: fadeOut 0.5s forwards;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
            }
            to {
                opacity: 0;
            }
        }
    </style>
</head>
<body>
    <div id="discordOverlay" class="discord-overlay"></div>
</body>
</html>