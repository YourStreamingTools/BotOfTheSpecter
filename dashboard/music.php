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
include '/var/www/config/cloudflare.php';
include 'userdata.php';
include 'user_db.php';
foreach ($profileData as $profile) {
    $timezone = $profile['timezone'];
    $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

// Fetch the files from the R2 bucket
function getR2MusicFiles() {
    global $access_key_id, $secret_access_key, $bucket_name, $r2_bucket_url;
    $folder = 'music/';
    $region = 'WNAM';
    $service = 's3';
    $host = parse_url($r2_bucket_url, PHP_URL_HOST);
    $endpoint = "{$r2_bucket_url}/{$bucket_name}?prefix={$folder}";
    $currentDate = gmdate('Ymd\THis\Z');
    $shortDate = gmdate('Ymd');
    // Create canonical request
    $canonicalUri = "/{$bucket_name}";
    $canonicalQueryString = "prefix=" . rawurlencode($folder);
    $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:UNSIGNED-PAYLOAD\nx-amz-date:{$currentDate}\n";
    $signedHeaders = "host;x-amz-content-sha256;x-amz-date";
    $payloadHash = "UNSIGNED-PAYLOAD";
    $canonicalRequest = "GET\n{$canonicalUri}\n{$canonicalQueryString}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
    // Create string to sign
    $algorithm = "AWS4-HMAC-SHA256";
    $credentialScope = "{$shortDate}/{$region}/{$service}/aws4_request";
    $stringToSign = "{$algorithm}\n{$currentDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
    // Calculate the signature
    $kSecret = "AWS4{$secret_access_key}";
    $kDate = hash_hmac('sha256', $shortDate, $kSecret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', "aws4_request", $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    // Add authorization header
    $authorizationHeader = "{$algorithm} Credential={$access_key_id}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: {$authorizationHeader}",
        "x-amz-content-sha256: UNSIGNED-PAYLOAD",
        "x-amz-date: {$currentDate}"
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        curl_close($ch);
        return [];
    }
    curl_close($ch);
    $xml = simplexml_load_string($response);
    if ($xml === false) {
        return [];
    }
    $files = [];
    foreach ($xml->Contents as $content) {
        $key = (string)$content->Key;
        if (str_ends_with($key, '.mp3')) {
            $files[] = str_replace('music/', '', $key);
        }
    }
    return $files;
}

// Fetch music files from R2 bucket
$musicFiles = getR2MusicFiles();
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
                            <span id="play-pause-btn" class="icon is-large has-text-success" style="cursor: pointer;">
                                <i id="play-pause-icon" class="fas fa-play-circle fa-2x"></i>
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
                            <span id="repeat-btn" class="icon is-large has-text-info" style="cursor: pointer;">
                                <i class="fas fa-redo fa-2x"></i>
                            </span>
                        </div>
                        <div class="column has-text-centered">
                            <span id="shuffle-btn" class="icon is-large has-text-primary" style="cursor: pointer;">
                                <i class="fas fa-random fa-2x"></i>
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
        <table class="table is-striped is-hoverable is-fullwidth">
            <thead>
                <tr>
                    <th style="width: 60px;">#</th>
                    <th>Title</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($musicFiles as $index => $file): ?>
                    <tr data-file="<?php echo htmlspecialchars($file); ?>">
                        <td><?php echo $index + 1; ?></td>
                        <td><?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Audio element for music playback -->
<audio id="audio-player" style="display: none;"></audio>

<!-- Music control scripts -->
<script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
<script>
    // Establish WebSocket connection
    let socket;
    const retryInterval = 5000;
    let reconnectAttempts = 0;
    let isPlaying = false;

    // Set default volume to 50% on page load
    document.addEventListener('DOMContentLoaded', () => {
        const audioPlayer = document.getElementById('audio-player');
        audioPlayer.volume = 0.5;
    });

    function initializeSocketListeners() {
        // Event listener for play/pause toggle
        document.getElementById('play-pause-btn').addEventListener('click', function() {
            const audioPlayer = document.getElementById('audio-player');
            const icon = document.getElementById('play-pause-icon');
            if (isPlaying) {
                console.log('Pause clicked');
                audioPlayer.pause();
                socket.emit('COMMAND', { command: 'pause' });
                icon.classList.remove('fa-pause-circle');
                icon.classList.add('fa-play-circle');
            } else {
                console.log('Play clicked');
                playSong(currentIndex);
                socket.emit('COMMAND', { command: 'play' });
                icon.classList.remove('fa-play-circle');
                icon.classList.add('fa-pause-circle');
            }
            isPlaying = !isPlaying;
        });

        // Event listener for volume control
        document.getElementById('volume-range').addEventListener('input', function() {
            const audioPlayer = document.getElementById('audio-player');
            audioPlayer.volume = this.value / 100; // Set volume (0.0 to 1.0)
            console.log('Volume set to:', this.value);
            socket.emit('COMMAND', { command: 'volume', value: this.value });
        });

        document.getElementById('prev-btn').addEventListener('click', function() {
            console.log('Previous clicked');
            currentIndex = (currentIndex - 1 + playlist.length) % playlist.length;
            playSong(currentIndex);
        });

        document.getElementById('next-btn').addEventListener('click', function() {
            console.log('Next clicked');
            if (shuffle) {
                currentIndex = Math.floor(Math.random() * playlist.length);
            } else {
                currentIndex = (currentIndex + 1) % playlist.length;
            }
            playSong(currentIndex);
        });

        document.getElementById('repeat-btn').addEventListener('click', function() {
            repeat = !repeat;
            console.log('Repeat:', repeat);
            this.classList.toggle('has-text-danger', repeat);
        });

        document.getElementById('shuffle-btn').addEventListener('click', function() {
            shuffle = !shuffle;
            console.log('Shuffle:', shuffle);
            this.classList.toggle('has-text-danger', shuffle);
        });

        socket.on('NOW_PLAYING', (data) => {
            if (data && data.song) {
                console.log('Server updated now playing:', data.song);
            }
        });

        // Automatically play the next song when the current one ends
        socket.on('SONG_ENDED', () => {
            if (repeat) {
                playSong(currentIndex);
            } else {
                document.getElementById('next-btn').click();
            }
        });
    }

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

            // Initialize event listeners after connection
            initializeSocketListeners();
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
    let repeat = false;
    let shuffle = false;
    let playlist = <?php echo json_encode($musicFiles); ?>;
    let currentIndex = 0;

    function playSong(index) {
        currentIndex = index;
        const nowPlayingElement = document.getElementById('now-playing');
        const song = playlist[currentIndex];
        const formattedTitle = song.replace('.mp3', '').replace(/_/g, ' ');
        nowPlayingElement.textContent = `🎵 ${formattedTitle}`;
        const audioPlayer = document.getElementById('audio-player');
        audioPlayer.src = `https://cdn.botofthespecter.com/music/${encodeURIComponent(song)}`;
        audioPlayer.play().then(() => {
            console.log(`Playing: ${formattedTitle}`);
            socket.emit('NOW_PLAYING', { song: formattedTitle });
        }).catch(error => {
            console.error('Error playing audio:', error);
        });
    }

    // Automatically play the next song when the current one ends
    document.getElementById('audio-player').addEventListener('ended', () => {
        if (repeat) {
            playSong(currentIndex);
        } else {
            document.getElementById('next-btn').click();
        }
    });

    // Start initial connection
    connectWebSocket();
</script>
</body>
</html>