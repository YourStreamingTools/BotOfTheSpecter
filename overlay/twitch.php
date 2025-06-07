<?php
include '/var/www/config/database.php';
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
            let socket;
            const retryInterval = 5000;
            let reconnectAttempts = 0;
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            if (!code) {
                alert('No code provided in the URL');
                return;
            }

            function connectWebSocket() {
                socket = io('wss://websocket.botofthespecter.com', {
                    reconnection: false
                });

                socket.on('connect', () => {
                    console.log('Connected to WebSocket server');
                    reconnectAttempts = 0;
                    socket.emit('REGISTER', { code: code, channel:'Overlay', name: 'Twitch Alerts' });
                });

                socket.on('disconnect', () => {
                    console.log('Disconnected from WebSocket server');
                    attemptReconnect();
                });

                socket.on('connect_error', (error) => {
                    console.error('Connection error:', error);
                    attemptReconnect();
                });

                socket.on('WELCOME', (data) => {
                    console.log('Server says:', data.message);
                });

                socket.on('TWITCH_FOLLOW', (data) => {
                    console.log('TWITCH_FOLLOW event received:', data);
                    showTwitchEventOverlay('New Follower!', `${data['twitch-username']} has followed!`);
                });

                socket.on('TWITCH_CHEER', (data) => {
                    console.log('TWITCH_CHEER event received:', data);
                    showTwitchEventOverlay('New Cheer!', `${data['twitch-username']} cheered ${data['twitch-cheer-amount']} bits!`);
                });

                socket.on('TWITCH_SUB', (data) => {
                    console.log('TWITCH_SUB event received:', data);
                    showTwitchEventOverlay('New Subscriber!', `${data['twitch-username']} subscribed at tier ${data['twitch-tier']} for ${data['twitch-sub-months']} months!`);
                });

                socket.on('TWITCH_RAID', (data) => {
                    console.log('TWITCH_RAID event received:', data);
                    showTwitchEventOverlay('New Raid!', `${data['twitch-username']} is raiding with ${data['twitch-raid']} viewers!`);
                });

                // Log all events
                socket.onAny((event, ...args) => {
                    console.log(`[onAny] Event: ${event}`, ...args);
                });
            }

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

            function attemptReconnect() {
                reconnectAttempts++;
                const delay = Math.min(retryInterval * reconnectAttempts, 30000);
                console.log(`Attempting to reconnect in ${delay / 1000} seconds...`);
                setTimeout(() => {
                    connectWebSocket();
                }, delay);
            }

            // Start initial connection
            connectWebSocket();
        });
    </script>
</head>
<body>
    <div id="twitchOverlay" class="twitch-overlay"></div>
</body>
</html>