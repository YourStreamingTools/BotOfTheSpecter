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
include "mod_access.php";
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
        <p class="subtitle">Stream DMCA-free music for your Twitch broadcasts and VODs</p>
    </div>
    <div class="card has-background-dark has-text-white" style="margin-top:20px;">
        <div class="card-content">
            <div class="columns is-mobile is-vcentered">
                <!-- Now Playing Section -->
                <div class="column is-half has-text-centered">
                    <h2 class="title is-5">Now Playing</h2>
                    <div style="display: flex; flex-direction: column; align-items: center; gap: 10px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <p id="now-playing" class="subtitle is-6" style="margin-bottom:0;">No song is currently playing</p>
                            <a id="refresh-now-playing" title="Refresh Now Playing" style="cursor:pointer; color:#209cee; display:flex; align-items:center;">
                                <span class="icon is-small"><i class="fas fa-sync-alt"></i></span>
                            </a>
                        </div>
                        <!-- Local playback toggle -->
                        <div>
                            <label class="checkbox" style="user-select:none;">
                                <input type="checkbox" id="local-playback-toggle" style="accent-color:#48c774;">
                                <span style="margin-left: 6px;">Play music locally on this page</span>
                            </label>
                        </div>
                    </div>
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
                            <input id="volume-range" type="range" min="0" max="100" value="10" class="slider is-small">
                            <p id="volume-percentage" class="has-text-white">Volume: 10%</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="box" style="margin-top:20px;">
        <h2 class="title is-4">Playlist</h2>
        <table class="table is-striped is-fullwidth">
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
                        <td style="cursor: pointer;"><?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add hidden audio element for local playback -->
<audio id="audio-player" style="display: none;"></audio>

<!-- Music control scripts -->
<script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
<script>
    let socket;
    const retryInterval = 5000;
    let reconnectAttempts = 0;
    let isPlaying = false;

    function initializeSocketListeners() {
        // Event listener for play/pause toggle
        document.getElementById('play-pause-btn').addEventListener('click', function() {
            const icon = document.getElementById('play-pause-icon');
            if (isPlaying) {
                socket.emit('MUSIC_COMMAND', { command: 'pause' });
                icon.classList.remove('fa-pause-circle');
                icon.classList.add('fa-play-circle');
            } else {
                socket.emit('MUSIC_COMMAND', { command: 'play' });
                icon.classList.remove('fa-play-circle');
                icon.classList.add('fa-pause-circle');
            }
            isPlaying = !isPlaying;
        });

        document.getElementById('prev-btn').addEventListener('click', function() {
            socket.emit('MUSIC_COMMAND', { command: 'prev' });
        });

        document.getElementById('next-btn').addEventListener('click', function() {
            socket.emit('MUSIC_COMMAND', { command: 'next' });
        });

        document.getElementById('repeat-btn').addEventListener('click', function() {
            repeat = !repeat;
            socket.emit('MUSIC_COMMAND', { command: 'repeat', value: repeat });
            this.classList.toggle('has-text-danger', repeat);
        });

        document.getElementById('shuffle-btn').addEventListener('click', function() {
            shuffle = !shuffle;
            socket.emit('MUSIC_COMMAND', { command: 'shuffle', value: shuffle });
            this.classList.toggle('has-text-danger', shuffle);
        });

        // Add click event listener to playlist rows
        document.querySelectorAll('tbody tr').forEach((row, index) => {
            row.addEventListener('click', () => {
                socket.emit('MUSIC_COMMAND', { command: 'play_index', index: index });
                // Update play button to "pause" state
                const icon = document.getElementById('play-pause-icon');
                icon.classList.remove('fa-play-circle');
                icon.classList.add('fa-pause-circle');
                isPlaying = true;
            });
        });

        // Restore volume control event listener
        document.getElementById('volume-range').addEventListener('input', function() {
            const volumePercentage = document.getElementById('volume-percentage');
            volumePercentage.textContent = `Volume: ${this.value}%`;
            socket.emit('MUSIC_COMMAND', { command: 'volume', value: this.value });
        });

        // Only declare refreshBtn once at the top of this function
        const refreshBtn = document.getElementById('refresh-now-playing');

        // Update refresh icon to show loading (spin) when loading
        refreshBtn.addEventListener('click', function() {
            const icon = refreshBtn.querySelector('i');
            icon.classList.add('fa-spin');
            socket.emit('MUSIC_COMMAND', { command: 'WHAT_IS_PLAYING' });
        });

        // Local playback toggle
        const localPlaybackToggle = document.getElementById('local-playback-toggle');
        const audioPlayer = document.getElementById('audio-player');
        let localPlayback = false;
        let playlist = <?php echo json_encode($musicFiles); ?>;
        let currentIndex = 0;

        localPlaybackToggle.addEventListener('change', function() {
            localPlayback = this.checked;
            // Optionally show/hide audio element or give user feedback
        });

        // Play/Pause
        document.getElementById('play-pause-btn').addEventListener('click', function() {
            const icon = document.getElementById('play-pause-icon');
            if (localPlayback) {
                if (audioPlayer.paused) {
                    audioPlayer.play();
                    icon.classList.remove('fa-play-circle');
                    icon.classList.add('fa-pause-circle');
                    isPlaying = true;
                } else {
                    audioPlayer.pause();
                    icon.classList.remove('fa-pause-circle');
                    icon.classList.add('fa-play-circle');
                    isPlaying = false;
                }
            } else {
                if (isPlaying) {
                    socket.emit('MUSIC_COMMAND', { command: 'pause' });
                    icon.classList.remove('fa-pause-circle');
                    icon.classList.add('fa-play-circle');
                } else {
                    socket.emit('MUSIC_COMMAND', { command: 'play' });
                    icon.classList.remove('fa-play-circle');
                    icon.classList.add('fa-pause-circle');
                }
                isPlaying = !isPlaying;
            }
        });

        // Previous
        document.getElementById('prev-btn').addEventListener('click', function() {
            if (localPlayback) {
                currentIndex = (currentIndex - 1 + playlist.length) % playlist.length;
                playSongLocal(currentIndex);
            } else {
                socket.emit('MUSIC_COMMAND', { command: 'prev' });
            }
        });

        // Next
        document.getElementById('next-btn').addEventListener('click', function() {
            if (localPlayback) {
                currentIndex = (currentIndex + 1) % playlist.length;
                playSongLocal(currentIndex);
            } else {
                socket.emit('MUSIC_COMMAND', { command: 'next' });
            }
        });

        // Repeat
        document.getElementById('repeat-btn').addEventListener('click', function() {
            repeat = !repeat;
            if (!localPlayback) {
                socket.emit('MUSIC_COMMAND', { command: 'repeat', value: repeat });
            }
            this.classList.toggle('has-text-danger', repeat);
        });

        // Shuffle
        document.getElementById('shuffle-btn').addEventListener('click', function() {
            shuffle = !shuffle;
            if (!localPlayback) {
                socket.emit('MUSIC_COMMAND', { command: 'shuffle', value: shuffle });
            }
            this.classList.toggle('has-text-danger', shuffle);
        });

        // Playlist row click
        document.querySelectorAll('tbody tr').forEach((row, index) => {
            row.addEventListener('click', () => {
                if (localPlayback) {
                    playSongLocal(index);
                    const icon = document.getElementById('play-pause-icon');
                    icon.classList.remove('fa-play-circle');
                    icon.classList.add('fa-pause-circle');
                    isPlaying = true;
                } else {
                    socket.emit('MUSIC_COMMAND', { command: 'play_index', index: index });
                    const icon = document.getElementById('play-pause-icon');
                    icon.classList.remove('fa-play-circle');
                    icon.classList.add('fa-pause-circle');
                    isPlaying = true;
                }
            });
        });

        // Volume
        document.getElementById('volume-range').addEventListener('input', function() {
            const volumePercentage = document.getElementById('volume-percentage');
            volumePercentage.textContent = `Volume: ${this.value}%`;
            if (localPlayback) {
                audioPlayer.volume = this.value / 100;
            } else {
                socket.emit('MUSIC_COMMAND', { command: 'volume', value: this.value });
            }
        });

        // Refresh Now Playing button
        refreshBtn.addEventListener('click', function() {
            refreshBtn.classList.add('is-loading');
            socket.emit('MUSIC_COMMAND', { command: 'WHAT_IS_PLAYING' });
        });

        // Local audio ended event
        audioPlayer.addEventListener('ended', function() {
            if (repeat) {
                playSongLocal(currentIndex);
            } else if (shuffle) {
                currentIndex = Math.floor(Math.random() * playlist.length);
                playSongLocal(currentIndex);
            } else {
                currentIndex = (currentIndex + 1) % playlist.length;
                playSongLocal(currentIndex);
            }
        });
    }

    function playSongLocal(index) {
        currentIndex = index;
        const nowPlayingElement = document.getElementById('now-playing');
        const song = playlist[currentIndex];
        const formattedTitle = song.replace('.mp3', '').replace(/_/g, ' ');
        nowPlayingElement.textContent = `🎵 ${formattedTitle}`;
        const audioPlayer = document.getElementById('audio-player');
        audioPlayer.src = `https://cdn.botofthespecter.com/music/${encodeURIComponent(song)}`;
        audioPlayer.volume = document.getElementById('volume-range').value / 100;
        audioPlayer.play();
        isPlaying = true;
        // Update play/pause icon
        const icon = document.getElementById('play-pause-icon');
        icon.classList.remove('fa-play-circle');
        icon.classList.add('fa-pause-circle');
    }

    function applyMusicSettings(settings) {
        // Update volume if present
        if (settings && typeof settings.volume !== 'undefined') {
            const volumeRange = document.getElementById('volume-range');
            const volumePercentage = document.getElementById('volume-percentage');
            volumeRange.value = settings.volume;
            volumePercentage.textContent = `Volume: ${settings.volume}%`;
        }
        // Update now playing if present
        if (settings && settings.now_playing) {
            const nowPlayingElement = document.getElementById('now-playing');
            nowPlayingElement.textContent = `🎵 ${settings.now_playing.title || settings.now_playing}`;
            const icon = document.getElementById('play-pause-icon');
            icon.classList.remove('fa-play-circle');
            icon.classList.add('fa-pause-circle');
            isPlaying = true;
        }
    }

    function connectWebSocket() {
        socket = io('wss://websocket.botofthespecter.com', {
            reconnection: false
        });

        socket.on('connect', () => {
            console.log('Connected to WebSocket server');
            reconnectAttempts = 0;

            socket.emit('REGISTER', {
                code: '<?php echo $api_key; ?>',
                channel: 'Dashboard', 
                name: 'Music Controller' 
            });

            // Only initialize event listeners after connection
            initializeSocketListeners();
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

        // Handle MUSIC_SETTINGS event
        socket.on('MUSIC_SETTINGS', (settings) => {
            applyMusicSettings(settings);
        });

        // Handle NOW_PLAYING event
        socket.on('NOW_PLAYING', (data) => {
            const nowPlayingElement = document.getElementById('now-playing');
            const refreshBtn = document.getElementById('refresh-now-playing');
            if (data && data.song) {
                nowPlayingElement.textContent = `🎵 ${data.song.title || data.song}`;
                // Optionally update play/pause icon based on playing state
                const icon = document.getElementById('play-pause-icon');
                icon.classList.remove('fa-play-circle');
                icon.classList.add('fa-pause-circle');
                isPlaying = true;
            } else {
                nowPlayingElement.textContent = 'No song is currently playing';
                const icon = document.getElementById('play-pause-icon');
                icon.classList.remove('fa-pause-circle');
                icon.classList.add('fa-play-circle');
                isPlaying = false;
            }
            if (refreshBtn) refreshBtn.querySelector('i').classList.remove('fa-spin');
        });

        // On SUCCESS, request music settings
        socket.on('SUCCESS', () => {
            // Only call settings after SUCCESS event
            socket.emit('MUSIC_COMMAND', { command: 'MUSIC_SETTINGS' });
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

    // Start initial connection
    connectWebSocket();
</script>
</body>
</html>