<?php
include '/var/www/config/database.php';
$primary_db_name = 'website';

$conn = new mysqli($db_servername, $db_username, $db_password, $primary_db_name);
$api_key = $_GET['code'] ?? '';

if ($conn->connect_error) {
    http_response_code(500);
    exit;
}

$username = '';
$stmt = $conn->prepare("SELECT username FROM users WHERE api_key = ?");
if ($stmt) {
    $stmt->bind_param("s", $api_key);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $username = $user['username'] ?? '';
    $stmt->close();
}

$music_source = 'system';
$music_playlist_filter = [];
if ($username) {
    $user_db = new mysqli($db_servername, $db_username, $db_password, $username);
    if (!$user_db->connect_error) {
        $prefRes = $user_db->query("SELECT music_source, music_playlist_filter FROM streamer_preferences WHERE id = 1");
        if ($prefRes && ($row = $prefRes->fetch_assoc())) {
            if (isset($row['music_source'])) {
                $ms = $row['music_source'];
                if (in_array($ms, ['system', 'user', 'both'], true)) {
                    $music_source = $ms;
                }
            }
            if (!empty($row['music_playlist_filter'])) {
                $decoded = json_decode($row['music_playlist_filter'], true);
                if (is_array($decoded)) {
                    $music_playlist_filter = array_values(array_filter($decoded, fn($v) => is_string($v) && $v !== ''));
                }
            }
        }
        $user_db->close();
    }
}

// System music directory
function getSystemMusicFiles() {
    $musicDir = '/var/www/cdn/music';
    $files = [];
    if (is_dir($musicDir)) {
        foreach (scandir($musicDir) as $file) {
            if (is_file("$musicDir/$file") && str_ends_with(strtolower($file), '.mp3')) {
                $files[] = $file;
            }
        }
    }
    return $files;
}

// Public user music directory (used when streamer preference = 'user')
function getUserMusicFiles($username) {
    $files = [];
    if (!$username) return $files;
    $musicDir = '/var/www/usermusic/' . $username;
    if (is_dir($musicDir)) {
        foreach (scandir($musicDir) as $file) {
            if (is_file("$musicDir/$file") && str_ends_with(strtolower($file), '.mp3')) {
                $files[] = $file;
            }
        }
    }
    return $files;
}

$systemMusicFiles = getSystemMusicFiles();
$userMusicFiles = getUserMusicFiles($username);
$userBaseUrl = $username ? "https://music.botspecter.com/{$username}/" : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Overlay DMCA Music</title>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
</head>
<body>
    <audio id="audio-player" preload="auto"></audio>
    <div id="now-playing"></div>
    <script>
        let socket;
        let currentSong = null;
        let currentSongData = null; // Store song data for replay
        let volume = 10;
        let musicSource = <?php echo json_encode($music_source); ?>; // 'system', 'user', or 'both'
        let excludedTracks = new Set(<?php echo json_encode(array_values($music_playlist_filter)); ?>);
        // systemPlaylist: tracks served from CDN
        let systemPlaylist = <?php
            echo json_encode(array_map(function($f) {
                return [
                    'file' => $f,
                    'title' => preg_replace('/_/', ' ', preg_replace('/\.mp3$/', '', $f))
                ];
            }, $systemMusicFiles));
        ?>;
        // userPlaylist: public URLs under music.botspecter.com/{username}/
        let userPlaylist = <?php
            echo json_encode(array_map(function($f) use ($userBaseUrl) {
                return [
                    'file' => $f,
                    'title' => preg_replace('/_/', ' ', preg_replace('/\.mp3$/', '', $f)),
                    'url' => $userBaseUrl ? $userBaseUrl . rawurlencode($f) : null
                ];
            }, $userMusicFiles));
        ?>;
        // active playlist (defaults to system)
        let playlist = systemPlaylist;
        let currentIndex = 0;
        let repeat = false;
        let shuffle = false;
        let playedHistory = new Set();
        const audioPlayer = document.getElementById('audio-player');
        const nowPlayingDiv = document.getElementById('now-playing');
        const VOLUME_SCALE = 0.1;
        let reconnectTimer = null;
        const urlParams = new URLSearchParams(window.location.search);
        const showNowPlaying = urlParams.has('nowplaying');
        const color = urlParams.get('color') || 'white';

        function showOverlayError(message, type) {
            let banner = document.getElementById('overlayErrorBanner');
            if (!banner) {
                banner = document.createElement('div');
                banner.id = 'overlayErrorBanner';
                document.body.appendChild(banner);
            }
            banner.textContent = message;
            banner.className = 'overlay-error-banner ' + (type === 'warn' ? 'overlay-error-banner-warn' : 'overlay-error-banner-danger');
            banner.style.display = 'block';
            if (type === 'warn') {
                clearTimeout(banner._timeoutId);
                banner._timeoutId = setTimeout(() => { banner.style.display = 'none'; }, 6000);
            }
        }

        const hasCode = !!urlParams.get('code');
        if (!hasCode) {
            showOverlayError('No code provided in the URL', 'danger');
        }
        if (showNowPlaying) {
            nowPlayingDiv.style.display = 'block';
            nowPlayingDiv.style.position = 'absolute';
            nowPlayingDiv.style.zIndex = '10000';
            nowPlayingDiv.style.fontSize = '24px';
            nowPlayingDiv.style.fontWeight = 'bold';
            nowPlayingDiv.style.maxWidth = '90vw';
            nowPlayingDiv.style.padding = '0 16px';
            nowPlayingDiv.style.boxSizing = 'border-box';
            nowPlayingDiv.style.whiteSpace = 'normal';
            nowPlayingDiv.style.overflowWrap = 'anywhere';
            nowPlayingDiv.style.wordBreak = 'break-word';
            nowPlayingDiv.style.textAlign = 'center';
            nowPlayingDiv.style.lineHeight = '1.2';
            let processedColor = color;
            if (!color.startsWith('#') && /^[0-9a-fA-F]{3,6}$/.test(color)) {
                processedColor = '#' + color;
            }
            nowPlayingDiv.style.color = processedColor;
            let textShadow = '2px 2px 4px rgba(0,0,0,0.8)';
            if (processedColor.toLowerCase() === 'black' || processedColor === '#000' || processedColor === '#000000') {
                textShadow = '2px 2px 4px rgba(255,255,255,0.8)';
            }
            nowPlayingDiv.style.textShadow = textShadow;
            nowPlayingDiv.style.top = '50%';
            nowPlayingDiv.style.left = '50%';
            nowPlayingDiv.style.transform = 'translate(-50%, -50%)';
        } else {
            nowPlayingDiv.style.display = 'none';
        }
        function setAudioVolume(newVolume) {
            const parsed = Number(newVolume);
            if (Number.isFinite(parsed)) {
                volume = Math.max(0, Math.min(100, parsed));
            }
            audioPlayer.volume = (volume / 100) * VOLUME_SCALE;
        }
        function scheduleReconnect() {
            if (reconnectTimer !== null) return;
            reconnectTimer = setTimeout(() => {
                reconnectTimer = null;
                connectWebSocket();
            }, 5000);
        }
        function getSongKey(song) {
            return song.url ? song.url : `https://cdn.botofthespecter.com/music/${encodeURIComponent(song.file)}`;
        }
        function playSong(url, songData = null) {
            if (!url) return;
            currentSong = url;
            audioPlayer.src = url;
            setAudioVolume(volume);
            audioPlayer.play().then(() => {
                if (songData && songData.file) {
                    currentSongData = {
                        file: songData.file,
                        title: songData.title || songData.file.replace('.mp3','').replace(/_/g,' ')
                    };
                    playedHistory.add(url);
                if (showNowPlaying) {
                    nowPlayingDiv.innerText = 'Now Playing: ' + currentSongData.title;
                }
                }
                if (socket && currentSongData && currentSongData.file) {
                    socket.emit('MUSIC_COMMAND', {
                        command: 'NOW_PLAYING',
                        song: {
                            title: currentSongData.title,
                            file: currentSongData.file
                        }
                    });
                }
            }).catch(() => {});
        }
        function stopSong() {
            audioPlayer.pause();
            audioPlayer.currentTime = 0;
            if (showNowPlaying) {
                nowPlayingDiv.innerText = '';
            }
        }
        function playSongByIndex(idx) {
            if (!playlist.length) return;
            const parsedIndex = Number(idx);
            if (!Number.isInteger(parsedIndex) || parsedIndex < 0 || parsedIndex >= playlist.length) {
                return;
            }
            currentIndex = parsedIndex;
            const song = playlist[currentIndex];
            const url = song.url ? song.url : `https://cdn.botofthespecter.com/music/${encodeURIComponent(song.file)}`;
            playSong(url, song);
        }
        function playNextSong() {
            // Use the active playlist (system, user, or both).
            if (repeat) {
                audioPlayer.currentTime = 0;
                audioPlayer.play();
                return;
            }
            if (shuffle && playlist.length > 1) {
                let unplayed = playlist.filter(song => !playedHistory.has(getSongKey(song)));
                if (unplayed.length === 0) {
                    playedHistory.clear();
                    unplayed = [...playlist];
                }
                const nextSong = unplayed[Math.floor(Math.random() * unplayed.length)];
                const nextIndex = playlist.findIndex(song => getSongKey(song) === getSongKey(nextSong));
                playSongByIndex(nextIndex);
            } else {
                currentIndex = (currentIndex + 1) % playlist.length;
                playSongByIndex(currentIndex);
            }
        }
        function trackFilterKey(song, isUser) {
            return isUser ? ('USER:' + song.file) : song.file;
        }
        function isSongEnabled(song, isUser) {
            return !excludedTracks.has(trackFilterKey(song, isUser));
        }
        function filterPlaylistByCheckboxes(list, isUser) {
            return list.filter(song => isSongEnabled(song, isUser));
        }
        function updateActivePlaylist() {
            const previousPlaylist = playlist;
            const currentKey = previousPlaylist[currentIndex] ? getSongKey(previousPlaylist[currentIndex]) : null;
            if (musicSource === 'both') {
                playlist = [
                    ...filterPlaylistByCheckboxes(userPlaylist, true),
                    ...filterPlaylistByCheckboxes(systemPlaylist, false),
                ];
                console.log('[Overlay] using combined playlist', playlist.length, 'active tracks');
            } else if (musicSource === 'user' && userPlaylist && userPlaylist.length > 0) {
                playlist = filterPlaylistByCheckboxes(userPlaylist, true);
                console.log('[Overlay] using user playlist', playlist.length, 'active tracks');
            } else {
                playlist = filterPlaylistByCheckboxes(systemPlaylist, false);
                console.log('[Overlay] using system playlist', playlist.length, 'active tracks');
            }
            if (playlist.length === 0) {
                currentIndex = 0;
                return;
            }
            if (currentKey !== null) {
                const sameIndex = playlist.findIndex(song => getSongKey(song) === currentKey);
                if (sameIndex >= 0) {
                    // Still-playing song just moved slots — re-anchor so playNextSong() advances correctly.
                    currentIndex = sameIndex;
                    return;
                }
                // Playing song was filtered out — resume from whichever track would have followed it.
                for (let i = 1; i <= previousPlaylist.length; i++) {
                    const nextKey = getSongKey(previousPlaylist[(currentIndex + i) % previousPlaylist.length]);
                    const nextIndex = playlist.findIndex(song => getSongKey(song) === nextKey);
                    if (nextIndex >= 0) {
                        currentIndex = (nextIndex - 1 + playlist.length) % playlist.length;
                        return;
                    }
                }
            }
            if (currentIndex >= playlist.length) {
                currentIndex = 0;
            }
        }
        function autoStartFirstSong() {
            updateActivePlaylist();
            if (playlist.length > 0) {
                playSongByIndex(0);
            } else {
                // no tracks available for the selected source — wait for NOW_PLAYING from controller
                console.log('[Overlay] no tracks available for current music source; waiting for NOW_PLAYING events');
            }
        }
        // Try to auto-start, but fallback to user gesture if autoplay fails
        function tryAutoStartFirstSong() {
            audioPlayer.muted = false;
            autoStartFirstSong();
            audioPlayer.play().catch(() => {
                waitForUserGestureThenAutoplay();
            });
        }
        // Prompt the user for interaction before unmuting and starting playback
        function waitForUserGestureThenAutoplay() {
            const overlay = document.createElement('div');
            overlay.style.position = 'fixed';
            overlay.style.top = '0';
            overlay.style.left = '0';
            overlay.style.width = '100vw';
            overlay.style.height = '100vh';
            overlay.style.background = 'rgba(0,0,0,0.85)';
            overlay.style.color = '#fff';
            overlay.style.display = 'flex';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            overlay.style.flexDirection = 'column';
            overlay.style.zIndex = '9999';
            overlay.innerHTML = `<h2 style="font-size:2em;margin-bottom:1em;">Click anywhere to start music playback</h2>`;
            document.body.appendChild(overlay);
            const handler = () => {
                audioPlayer.muted = false;
                autoStartFirstSong();
                overlay.remove();
                document.body.removeEventListener('click', handler);
            };
            document.body.addEventListener('click', handler, { once: true });
        }
        audioPlayer.addEventListener('ended', function() {
            playNextSong();
        });
        function connectWebSocket() {
            socket = io('wss://websocket.botofthespecter.com', { reconnection: false });
            if (urlParams.has('debug')) {
                socket.onAny((event, ...args) => {
                    console.log('Event:', event, ...args);
                });
            }
            socket.on('connect', () => {
                if (reconnectTimer !== null) {
                    clearTimeout(reconnectTimer);
                    reconnectTimer = null;
                }
                const urlParams = new URLSearchParams(window.location.search);
                const code = urlParams.get('code');
                if (!code) return;
                socket.emit('REGISTER', { code: code, channel: 'Overlay', name: 'DMCA' });
                tryAutoStartFirstSong();
            });
            socket.on('disconnect', () => {
                scheduleReconnect();
            });
            socket.on('connect_error', () => {
                scheduleReconnect();
            });
            socket.on('SUCCESS', () => {
                socket.emit('MUSIC_COMMAND', { command: 'MUSIC_SETTINGS' });
            });
            socket.on('MUSIC_SETTINGS', (settings) => {
                if (typeof settings.volume !== 'undefined') {
                    setAudioVolume(settings.volume);
                }
                if (typeof settings.repeat !== 'undefined') {
                    repeat = !!settings.repeat;
                }
                if (typeof settings.shuffle !== 'undefined') {
                    shuffle = !!settings.shuffle;
                }
                if (typeof settings.music_source !== 'undefined') {
                    musicSource = settings.music_source || 'system';
                    console.log('[Overlay] music_source set to', musicSource);
                }
                if (Array.isArray(settings.playlist_filter)) {
                    excludedTracks = new Set(settings.playlist_filter);
                    console.log('[Overlay] playlist_filter updated', excludedTracks.size, 'excluded');
                }
                updateActivePlaylist();
            });
            socket.on('NOW_PLAYING', (data) => {
                if (data?.song?.url) {
                    // Server provided a direct URL (recommended for private/user uploads)
                    playSong(data.song.url, data.song);
                } else if (data?.song?.file) {
                    const idx = playlist.findIndex(song => song.file === data.song.file);
                    if (idx >= 0) playSongByIndex(idx);
                } else {
                    stopSong();
                    currentSongData = null;
                    if (showNowPlaying) {
                        nowPlayingDiv.innerText = '';
                    }
                }
            });
            socket.on('MUSIC_COMMAND', (data) => {
                if (!data || !data.command) return;
                switch (data.command) {
                    case 'play': audioPlayer.play(); break;
                    case 'pause': audioPlayer.pause(); break;
                    case 'next': playNextSong(); break;
                    case 'prev':
                        if (!playlist.length) break;
                        currentIndex = (currentIndex - 1 + playlist.length) % playlist.length;
                        playSongByIndex(currentIndex);
                        break;
                    case 'play_index':
                        if (typeof data.index !== 'undefined') {
                            playSongByIndex(Number(data.index));
                        }
                        break;
                    case 'MUSIC_SETTINGS':
                        if (typeof data.volume !== 'undefined') {
                            setAudioVolume(data.volume);
                        }
                        if (typeof data.repeat !== 'undefined') repeat = !!data.repeat;
                        if (typeof data.shuffle !== 'undefined') shuffle = !!data.shuffle;
                        break;
                }
            });
            socket.on('PLAY', () => audioPlayer.play());
            socket.on('PAUSE', () => audioPlayer.pause());
            socket.on('WHAT_IS_PLAYING', () => {
                socket.emit('MUSIC_COMMAND', {
                    command: 'NOW_PLAYING',
                    song: currentSongData ?? null
                });
            });
        }
        if (hasCode) {
            document.body.addEventListener('click', () => {
                audioPlayer.play().catch(() => {});
            }, { once: true });
            connectWebSocket();
        }
    </script>
</body>
</html>