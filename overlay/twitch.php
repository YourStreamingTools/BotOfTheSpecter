<?php
$db_servername = 'sql.botofthespecter.com';
$db_username = ''; // CHANGE TO MAKE THIS WORK
$db_password = ''; // CHANGE TO MAKE THIS WORK
$primary_db_name = 'website';

$conn = new mysqli($db_servername, $db_username, $db_password, $primary_db_name);
$api_key = $_GET['code'];

$stmt = $conn->prepare("SELECT username FROM users WHERE api_key = ?");
$stmt->bind_param("s", $api_key);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$username = $user['username'];

$db = new PDO("mysql:host=$db_servername;dbname=$username", $db_username, $db_password);
$stmt = $db->prepare("SELECT * FROM twitch_alerts");
$stmt->execute();
$alertSettings = $stmt->fetch(PDO::FETCH_ASSOC);
?>
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

            socket.on('TWITCH_FOLLOW', (data) => {
                console.log('Twitch Follow:', data);
                showTwitchEventOverlay('New Follower!', `${data['twitch-username']} has followed!`);
            });

            socket.on('TWITCH_CHEER', (data) => {
                console.log('Twitch Cheer:', data);
                showTwitchEventOverlay('New Cheer!', `${data['twitch-username']} cheered ${data['twitch-cheer-amount']} bits!`);
            });

            socket.on('TWITCH_SUB', (data) => {
                console.log('Twitch Sub:', data);
                showTwitchEventOverlay('New Subscriber!', `${data['twitch-username']} subscribed at tier ${data['twitch-tier']} for ${data['twitch-sub-months']} months!`);
            });

            socket.on('TWITCH_RAID', (data) => {
                console.log('Twitch Raid:', data);
                showTwitchEventOverlay('New Raid!', `${data['twitch-username']} is raiding with ${data['twitch-raid']} viewers!`);
            });

            function showTwitchEventOverlay(title, message) {
                const twitchOverlay = document.getElementById('twitchOverlay');
                twitchOverlay.innerHTML = `
                    <div class="overlay-content">
                        <div class="overlay-title">${title}</div>
                        <div class="overlay-message">${message}</div>
                    </div>
                `;
                twitchOverlay.classList.add('show');
                twitchOverlay.style.display = 'block';

                setTimeout(() => {
                    twitchOverlay.classList.add('hide');
                    twitchOverlay.classList.remove('show');
                }, 10000);

                setTimeout(() => {
                    twitchOverlay.style.display = 'none';
                }, 11000);
            }

            // Log all events
            socket.onAny((event, ...args) => {
                console.log(`Event: ${event}`, args);
            });
        });
    </script>
</head>
<body>
    <div id="twitchOverlay" class="twitch-overlay"></div>
</body>
</html>