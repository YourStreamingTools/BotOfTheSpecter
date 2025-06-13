<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$today = new DateTime();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title and Initial Variables
$pageTitle = "Music Dashboard";

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Fetch the files from the local music directory
function getLocalMusicFiles() {
    $musicDir = '/var/www/cdn/music';
    $files = [];
    if (is_dir($musicDir)) {
        $musicFiles = scandir($musicDir);
        foreach ($musicFiles as $file) {
            if (str_ends_with($file, '.mp3')) {
                $files[] = $file;
            }
        }
    }
    return $files;
}

// Fetch music files from local directory
$musicFiles = getLocalMusicFiles();

ob_start();
?>
<div class="has-text-centered mb-6 has-text-white">
    <h1 class="title is-2 has-text-primary has-text-white">
        <span class="icon-text" style="display: inline-flex; align-items: center; justify-content: center;">
            <span class="icon is-large" style="display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-music"></i>
            </span>
            <span style="display: flex; align-items: center; margin-left: 0.5em;"><?php echo t('music_dashboard_title'); ?></span>
        </span>
    </h1>
    <p class="subtitle is-5 has-text-white"><?php echo t('music_dashboard_subtitle'); ?></p>
</div>
<div class="card mb-5 has-background-primary-gradient has-text-white">
    <div class="card-content has-text-white">
        <div class="columns is-vcentered is-centered has-text-white">
            <div class="column is-half has-text-white">
                <div class="media" style="display: flex; align-items: center; justify-content: center;">
                    <div class="media-content" style="width: 100%;">
                        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                            <div style="display: flex; align-items: center; justify-content: center;">
                                <h2 class="title is-6 has-text-white mb-1" style="margin-bottom: 0.25rem; display: flex; align-items: center;"><?php echo t('music_now_playing'); ?></h2>
                            </div>
                            <div style="display: flex; align-items: center; justify-content: center; margin-top: 0.5rem; margin-bottom: 0.5rem;">
                                <p id="now-playing" class="subtitle is-7 has-text-white" style="margin: 0; display: flex; align-items: center;"><?php echo t('music_no_song_playing'); ?></p>
                                <button id="refresh-now-playing" class="button is-small is-white is-outlined is-rounded" title="<?php echo t('music_refresh_now_playing'); ?>" style="margin-left: 0.75rem; display: flex; align-items: center; justify-content: center;">
                                    <span class="icon is-small" style="display: flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-sync-alt"></i>
                                    </span>
                                </button>
                            </div>
                            <div class="field mt-3" style="display: flex; align-items: center; justify-content: center;">
                                <div class="control">
                                    <label class="checkbox has-text-white" style="display: flex; align-items: center;">
                                        <input type="checkbox" id="local-playback-toggle" class="mr-2" style="margin-right: 0.5em;">
                                        <span class="is-size-7 has-text-white" style="display: flex; align-items: center;"><?php echo t('music_play_locally'); ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="column is-half has-text-white">
                <div class="columns is-mobile is-multiline is-flex-direction-column is-align-items-center is-justify-content-center" style="align-items: center; justify-content: center;">
                    <div class="column is-full" style="display: flex; justify-content: center;">
                        <div class="field is-grouped is-grouped-centered" style="display: flex; align-items: center;">
                            <div class="control" style="display: flex; align-items: center;">
                                <button id="prev-btn" class="button is-medium is-white is-outlined is-rounded" style="display: flex; align-items: center; justify-content: center;">
                                    <span class="icon" style="display: flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-step-backward"></i>
                                    </span>
                                </button>
                            </div>
                            <div class="control" style="display: flex; align-items: center;">
                                <button id="play-pause-btn" class="button is-large is-success is-rounded" style="display: flex; align-items: center; justify-content: center;">
                                    <span class="icon" style="display: flex; align-items: center; justify-content: center;">
                                        <i id="play-pause-icon" class="fas fa-play"></i>
                                    </span>
                                </button>
                            </div>
                            <div class="control" style="display: flex; align-items: center;">
                                <button id="next-btn" class="button is-medium is-white is-outlined is-rounded" style="display: flex; align-items: center; justify-content: center;">
                                    <span class="icon" style="display: flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-step-forward"></i>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="column is-full" style="display: flex; flex-direction: column; align-items: center;">
                        <div class="field is-grouped is-grouped-centered mb-3" style="display: flex; align-items: center;">
                            <div class="control" style="display: flex; align-items: center;">
                                <button id="repeat-btn"
                                    class="button is-small is-rounded"
                                    style="display: flex; align-items: center;"
                                    type="button">
                                    <span class="icon is-small" style="display: flex; align-items: center;">
                                        <i class="fas fa-redo"></i>
                                    </span>
                                    <span style="margin-left: 0.25em; display: flex; align-items: center;"><?php echo t('music_repeat'); ?></span>
                                </button>
                            </div>
                            <div class="control" style="display: flex; align-items: center;">
                                <button id="shuffle-btn"
                                    class="button is-small is-rounded"
                                    style="display: flex; align-items: center;"
                                    type="button">
                                    <span class="icon is-small" style="display: flex; align-items: center;">
                                        <i class="fas fa-random"></i>
                                    </span>
                                    <span style="margin-left: 0.25em; display: flex; align-items: center;"><?php echo t('music_shuffle'); ?></span>
                                </button>
                            </div>
                        </div>
                        <div class="field is-grouped is-grouped-centered is-align-items-center mt-4" style="display: flex; align-items: center; justify-content: center;">
                            <label class="label is-small has-text-white mr-3 mb-0" style="min-width:60px; display: flex; align-items: center;"><?php echo t('music_volume'); ?></label>
                            <div class="control is-expanded" style="max-width: 250px; display: flex; align-items: center;">
                                <input id="volume-range" type="range" min="0" max="100" value="0"
                                    class="modern-volume"
                                    style="width: 100%;">
                            </div>
                            <div class="control ml-3" style="display: flex; align-items: center;">
                                <span id="volume-percentage" class="tag is-white is-rounded" style="display: flex; align-items: center;"><?php echo t('music_loading'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card has-text-white">
    <header class="card-header has-text-white">
        <h2 class="card-header-title is-size-4 has-text-white">
            <span class="icon-text" style="display: flex; align-items: center;">
                <span class="icon" style="display: flex; align-items: center;">
                    <i class="fas fa-list-music"></i>
                </span>
                <span style="margin-left: 0.5em;"><?php echo t('music_playlist'); ?></span>
            </span>
        </h2>
        <div class="card-header-icon">
            <span class="tag is-info is-rounded"><?php echo count($musicFiles); ?> <?php echo t('music_songs'); ?></span>
        </div>
    </header>
    <div class="card-content p-0 has-text-white">
        <div class="field" style="padding: 1rem 1rem 0.5rem 1rem;">
            <div class="control has-icons-left">
                <input id="searchInput" class="input is-rounded" type="text" placeholder="<?php echo t('music_search_playlist'); ?>">
                <span class="icon is-left">
                    <i class="fas fa-search"></i>
                </span>
            </div>
        </div>
        <div class="table-container playlist-container has-text-white">
            <table class="table is-fullwidth has-text-white" id="commandsTable">
                <thead class="has-text-white">
                    <tr>
                        <th class="has-text-centered has-text-weight-bold is-narrow has-text-white">#</th>
                        <th class="has-text-white">
                            <span class="icon-text" style="display: flex; align-items: center;">
                                <span class="icon is-small" style="display: flex; align-items: center;">
                                    <i class="fas fa-music"></i>
                                </span>
                                <span style="margin-left: 0.5em;"><?php echo t('music_title'); ?></span>
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody class="has-text-white">
                    <?php foreach ($musicFiles as $index => $file): ?>
                        <tr data-file="<?php echo htmlspecialchars($file); ?>" class="playlist-row is-clickable has-text-white">
                            <td class="has-text-centered has-text-weight-semibold has-text-grey is-narrow has-text-white">
                                <?php echo $index + 1; ?>
                            </td>
                            <td class="is-family-secondary has-text-white">
                                <?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Hidden audio element for local playback -->
<audio id="audio-player" class="is-hidden"></audio>
<?php
$content = ob_get_clean();

ob_start();
?>
<script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
<script>
    let socket;
    const retryInterval = 5000;
    let reconnectAttempts = 0;
    let isPlaying = false;
    let volumeInitialized = false;
    let settingsTimeout;
    let repeat = false;
    let shuffle = false;
    let localPlayback = false;
    let playlist = <?php echo json_encode($musicFiles); ?>;
    let currentIndex = 0;
    let lastEmittedVolume = null;
    function getNextIndex() {
        if (shuffle) {
            let next;
            do {
                next = Math.floor(Math.random() * playlist.length);
            } while (playlist.length > 1 && next === currentIndex);
            return next;
        } else {
            return (currentIndex + 1) % playlist.length;
        }
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
        icon.classList.remove('fa-play');
        icon.classList.add('fa-pause');
    }

    document.addEventListener('DOMContentLoaded', function() {
        const audioPlayer = document.getElementById('audio-player');
        audioPlayer.addEventListener('ended', function() {
            if (repeat) {
                playSongLocal(currentIndex);
            } else {
                const nextIndex = getNextIndex();
                if (!shuffle && nextIndex === 0 && currentIndex === playlist.length - 1) {
                    // End of playlist, do not repeat unless repeat is enabled
                    isPlaying = false;
                    const icon = document.getElementById('play-pause-icon');
                    icon.classList.remove('fa-pause');
                    icon.classList.add('fa-play');
                } else {
                    playSongLocal(nextIndex);
                }
            }
        });
    });

    function applyMusicSettings(settings) {
        // Update volume if present
        if (settings && typeof settings.volume !== 'undefined') {
            const volumeRange = document.getElementById('volume-range');
            const volumePercentage = document.getElementById('volume-percentage');
            volumeRange.value = settings.volume;
            volumePercentage.textContent = `${settings.volume}%`;
            volumeInitialized = true;
        }
        // Update now playing if present
        if (settings && settings.now_playing) {
            const nowPlayingElement = document.getElementById('now-playing');
            nowPlayingElement.textContent = `🎵 ${settings.now_playing.title || settings.now_playing}`;
            const icon = document.getElementById('play-pause-icon');
            icon.classList.remove('fa-play');
            icon.classList.add('fa-pause');
            isPlaying = true;
        }
        if (settings && typeof settings.repeat !== 'undefined') {
            repeat = !!settings.repeat;
            const repeatBtn = document.getElementById('repeat-btn');
            repeatBtn.classList.toggle('is-primary', repeat);
            repeatBtn.classList.toggle('is-white', !repeat);
            repeatBtn.classList.remove('has-text-white');
            repeatBtn.classList.toggle('has-text-black', true);
        }
        if (settings && typeof settings.shuffle !== 'undefined') {
            shuffle = !!settings.shuffle;
            const shuffleBtn = document.getElementById('shuffle-btn');
            shuffleBtn.classList.toggle('is-primary', shuffle);
            shuffleBtn.classList.toggle('is-white', !shuffle);
            shuffleBtn.classList.remove('has-text-white');
            shuffleBtn.classList.toggle('has-text-black', true);
        }
    }

    function initializeSocketListeners() {
        // Add click event listener to playlist rows
        document.querySelectorAll('.playlist-row').forEach((row, index) => {
            row.addEventListener('click', () => {
                if (localPlayback) {
                    playSongLocal(index);
                    const icon = document.getElementById('play-pause-icon');
                    icon.classList.remove('fa-play');
                    icon.classList.add('fa-pause');
                    isPlaying = true;
                } else {
                    // Get the song title for the selected index
                    const song = playlist[index];
                    const songTitle = song.replace('.mp3', '').replace(/_/g, ' ');
                    // Emit event to play the selected file by index and update now playing
                    socket.emit('MUSIC_COMMAND', { command: 'play_index', index: index });
                    socket.emit('NOW_PLAYING', { song: { title: songTitle, file: song } });
                    console.log('Sent MUSIC_COMMAND play_index:', index, 'and NOW_PLAYING:', songTitle);
                    // Immediately update the UI for Now Playing
                    const nowPlayingElement = document.getElementById('now-playing');
                    nowPlayingElement.textContent = `🎵 ${songTitle}`;
                    const icon = document.getElementById('play-pause-icon');
                    icon.classList.remove('fa-play');
                    icon.classList.add('fa-pause');
                    isPlaying = true;
                }
            });
        });

        // Only declare refreshBtn once at the top of this function
        const refreshBtn = document.getElementById('refresh-now-playing');
        // Local playback toggle
        const localPlaybackToggle = document.getElementById('local-playback-toggle');
        const audioPlayer = document.getElementById('audio-player');
        let localPlayback = false;
        let playlist = <?php echo json_encode($musicFiles); ?>;
        let currentIndex = 0;
        localPlaybackToggle.addEventListener('change', function() {
            localPlayback = this.checked;
            if (!localPlayback && socket) {
                socket.emit('MUSIC_COMMAND', { command: 'MUSIC_SETTINGS' });
            }
        });

        // Play/Pause
        document.getElementById('play-pause-btn').addEventListener('click', function() {
            const icon = document.getElementById('play-pause-icon');
            if (localPlayback) {
                if (audioPlayer.paused) {
                    audioPlayer.play();
                    icon.classList.remove('fa-play');
                    icon.classList.add('fa-pause');
                    isPlaying = true;
                } else {
                    audioPlayer.pause();
                    icon.classList.remove('fa-pause');
                    icon.classList.add('fa-play');
                    isPlaying = false;
                }
            } else {
                if (isPlaying) {
                    socket.emit('MUSIC_COMMAND', { command: 'pause' });
                    icon.classList.remove('fa-pause');
                    icon.classList.add('fa-play');
                } else {
                    socket.emit('MUSIC_COMMAND', { command: 'play' });
                    icon.classList.remove('fa-play');
                    icon.classList.add('fa-pause');
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
            this.classList.toggle('is-primary', repeat);
            this.classList.toggle('is-white', !repeat);
            this.classList.remove('has-text-white');
            this.classList.add('has-text-black');
            socket.emit('MUSIC_COMMAND', { command: 'MUSIC_SETTINGS', repeat: repeat, shuffle: shuffle, volume: document.getElementById('volume-range').value });
        });

        // Shuffle
        document.getElementById('shuffle-btn').addEventListener('click', function() {
            shuffle = !shuffle;
            this.classList.toggle('is-primary', shuffle);
            this.classList.toggle('is-white', !shuffle);
            this.classList.remove('has-text-white');
            this.classList.add('has-text-black');
            socket.emit('MUSIC_COMMAND', { command: 'MUSIC_SETTINGS', repeat: repeat, shuffle: shuffle, volume: document.getElementById('volume-range').value });
        });

        // Volume
        document.getElementById('volume-range').addEventListener('input', function() {
            const volumePercentage = document.getElementById('volume-percentage');
            volumePercentage.textContent = `${this.value}%`;
            if (localPlayback) {
                audioPlayer.volume = this.value / 100;
            } else {
                const newVolume = this.value / 100;
                if (lastEmittedVolume !== newVolume) {
                    lastEmittedVolume = newVolume;
                    socket.emit('MUSIC_COMMAND', { command: 'volume', value: this.value });
                }
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
        icon.classList.remove('fa-play');
        icon.classList.add('fa-pause');
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

            // Set a timeout to fallback to default volume if no settings received
            settingsTimeout = setTimeout(() => {
                if (!volumeInitialized) {
                    const volumeRange = document.getElementById('volume-range');
                    const volumePercentage = document.getElementById('volume-percentage');
                    volumeRange.value = 10;
                    volumePercentage.textContent = '10%';
                    socket.emit('MUSIC_COMMAND', { command: 'volume', value: 10 });
                    console.log('No MUSIC_SETTINGS received, defaulting volume to 10% and emitting to server.');
                    volumeInitialized = true;
                }
            }, 3000); // 3 seconds
        });

        // Log all events and their data to the browser console
        socket.onAny((event, ...args) => {
            console.log('Event:', event, ...args);
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
            if (settingsTimeout) clearTimeout(settingsTimeout);
        });

        // Handle NOW_PLAYING event
        socket.on('NOW_PLAYING', (data) => {
            const nowPlayingElement = document.getElementById('now-playing');
            const refreshBtn = document.getElementById('refresh-now-playing');
            if (data && data.song) {
                nowPlayingElement.textContent = `🎵 ${data.song.title || data.song.file || data.song}`;
                const icon = document.getElementById('play-pause-icon');
                icon.classList.remove('fa-play');
                icon.classList.add('fa-pause');
                isPlaying = true;
            } else if (data && data.error) {
                nowPlayingElement.textContent = data.error;
                isPlaying = false;
            } else {
                nowPlayingElement.textContent = '<?php echo t('music_no_song_playing'); ?>';
                const icon = document.getElementById('play-pause-icon');
                icon.classList.remove('fa-pause');
                icon.classList.add('fa-play');
                isPlaying = false;
            }
            if (refreshBtn) refreshBtn.classList.remove('is-loading');
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
<?php
// Get the buffered content
$scripts = ob_get_clean();
// Include the layout template
include 'layout.php';
?>