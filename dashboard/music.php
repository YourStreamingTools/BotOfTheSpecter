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
            <div class="columns is-mobile is-vcentered">
                <!-- Now Playing Section -->
                <div class="column is-half has-text-left">
                    <h2 class="title is-5">Now Playing</h2>
                    <p id="now-playing" class="subtitle is-6">No song is currently playing</p>
                </div>
                <!-- Controls Section -->
                <div class="column is-half">
                    <div class="columns is-mobile is-centered is-vcentered">
                        <div class="column has-text-centered">
                            <span id="play-btn" class="icon is-large has-text-success" style="cursor: pointer;">
                                <i class="fas fa-play-circle fa-2x"></i>
                            </span>
                        </div>
                        <div class="column has-text-centered">
                            <span id="pause-btn" class="icon is-large has-text-warning" style="cursor: pointer;">
                                <i class="fas fa-pause-circle fa-2x"></i>
                            </span>
                        </div>
                        <div class="column has-text-centered">
                            <span id="prev-btn" class="icon is-large has-text-link" style="cursor: pointer;">
                                <i class="fas fa-step-backward fa-2x"></i>
                            </span>
                        </div>
                        <div class="column has-text-centered">
                            <span id="next-btn" class="icon is-large has-text-link" style="cursor: pointer;">
                                <i class="fas fa-step-forward fa-2x"></i>
                            </span>
                        </div>
                        <div class="column has-text-centered">
                            <input id="volume-range" type="range" min="0" max="100" value="50" class="slider is-small">
                        </div>
                    </div>
                </div>
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

        // Handle connection event
        socket.on('connect', () => {
            console.log('Connected to WebSocket server');
            reconnectAttempts = 0;

            socket.emit('REGISTER', {
                code: '<?php echo $api_key; ?>',
                channel: 'Dashboard', 
                name: 'Music Controller' 
            });
        });

        // Log all events
        socket.onAny((event, ...args) => {
            console.log(`Event: ${event}`, args);
        });

        // Handle disconnection event
        socket.on('disconnect', () => {
            console.log('Disconnected from WebSocket server');
            attemptReconnect();
        });

        // Handle connection error event
        socket.on('connect_error', (error) => {
            console.error('Connection error:', error);
            attemptReconnect();
        });

        // Handle server WELCOME event
        socket.on('WELCOME', (data) => {
            console.log('Server says:', data.message);
        });

        // Handle NOW_PLAYING event
        socket.on('NOW_PLAYING', (data) => {
            const nowPlayingElement = document.getElementById('now-playing');
            if (data && data.song) {
                nowPlayingElement.textContent = `🎵 ${data.song.title} by ${data.song.artist}`;
            } else {
                nowPlayingElement.textContent = 'No song is currently playing';
            }
        });
    }

    // Handle reconnection attempts
    function attemptReconnect() {
        reconnectAttempts++;
        const delay = Math.min(retryInterval * reconnectAttempts, 30000); // Max delay of 30 seconds
        console.log(`Attempting to reconnect in ${delay / 1000} seconds...`);
        setTimeout(() => {
            connectWebSocket();
        }, delay);
    }
    // Event listeners to send commands via WebSocket
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