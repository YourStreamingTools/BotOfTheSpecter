<?php 
// Initialize the session
session_start();
$today = new DateTime();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title and Initial Variables
$title = "Music Dashboard";

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'user_db.php';
foreach ($profileData as $profile) {
    $timezone = $profile['timezone'];
    $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);
?>
<!doctype html>
<html lang="en">
    <head>
        <!-- Header -->
        <?php include('header.php'); ?>
        <!-- /Header -->
        <style>
            .music-hero { text-align: center; margin-bottom: 30px; }
            .music-card { background: #2a2a2a; border-radius: 8px; padding: 20px; }
            .album-cover { width: 100%; max-width: 300px; margin: 0 auto 20px; }
            .album-cover img { width: 100%; border-radius: 8px; }
            .music-controls { text-align: center; }
            .music-controls button { margin: 5px; }
            .volume-control { width: 80%; margin: 20px auto; }
            .playlist { margin-top: 30px; }
        </style>
    </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
    <br>
    <div class="notification is-danger has-text-centered">
        <strong>SYSTEM COMING SOON</strong>
    </div>
    <br>
    <div class="has-text-centered" style="margin-bottom:20px;">
        <h1 class="title">Music Dashboard</h1>
        <p class="subtitle">Control the music stream for your broadcast</p>
    </div>
    <div class="card has-background-dark has-text-white" style="margin-top:20px;">
        <div class="card-content">
            <div class="columns is-mobile is-multiline is-centered">
                <div class="column is-half has-text-centered">
                    <button id="prev-btn" class="button is-link is-fullwidth is-small" disabled>Previous</button>
                </div>
                <div class="column is-half has-text-centered">
                    <button id="play-btn" class="button is-success is-fullwidth is-small" disabled>Play</button>
                </div>
                <div class="column is-half has-text-centered" style="margin-top:10px;">
                    <button id="pause-btn" class="button is-warning is-fullwidth is-small" disabled>Pause</button>
                </div>
                <div class="column is-half has-text-centered" style="margin-top:10px;">
                    <button id="next-btn" class="button is-link is-fullwidth is-small" disabled>Next</button>
                </div>
            </div>
            <div class="field" style="margin-top:15px;">
                <label class="label has-text-white">Volume</label>
                <input id="volume-range" type="range" min="0" max="100" value="50" class="slider is-fullwidth">
            </div>
        </div>
    </div>
    <div class="box" style="margin-top:20px;">
        <h2 class="title is-4">Playlist</h2>
        <ul>
            <li>Track 1</li>
            <li>Track 2</li>
            <li>Track 3</li>
        </ul>
    </div>
</div>

<!-- Music control scripts -->
<script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
<script>
    // Establish WebSocket connection
    let socket;
    const retryInterval = 5000;
    let reconnectAttempts = 0;

    function connectWebSocket() {
        socket = io('wss://websocket.botofthespecter.com', {
            reconnection: false
        });

        socket.on('connect', () => {
            console.log('Connected to WebSocket server');
            reconnectAttempts = 0;
            socket.emit('REGISTER', { code: 'MusicPage', channel: 'MusicControl', name: 'MusicDashboard' });
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

        // Log all events
        socket.onAny((event, ...args) => {
            console.log(`Event: ${event}`, args);
        });
    }

    function attemptReconnect() {
        reconnectAttempts++;
        const delay = Math.min(retryInterval * reconnectAttempts, 30000); // Max delay of 30 seconds
        console.log(`Attempting to reconnect in ${delay / 1000} seconds...`);
        setTimeout(() => {
            connectWebSocket();
        }, delay);
    }
    // Updated event listeners to send commands via WebSocket
    document.getElementById('play-btn').addEventListener('click', function() {
        console.log('Play clicked');
        socket.emit('COMMAND', { command: 'play' });
    });
    document.getElementById('pause-btn').addEventListener('click', function() {
        console.log('Pause clicked');
        socket.emit('COMMAND', { command: 'pause' });
    });
    document.getElementById('prev-btn').addEventListener('click', function() {
        console.log('Previous clicked');
        socket.emit('COMMAND', { command: 'previous' });
    });
    document.getElementById('next-btn').addEventListener('click', function() {
        console.log('Next clicked');
        socket.emit('COMMAND', { command: 'next' });
    });
    document.getElementById('volume-range').addEventListener('input', function() {
        console.log('Volume: ' + this.value);
        socket.emit('COMMAND', { command: 'volume', value: this.value });
    });
    // Start initial connection
    connectWebSocket();
</script>
</body>
</html>