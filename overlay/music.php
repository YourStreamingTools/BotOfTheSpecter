<?php
// Fetch music files from the local music directory
function getLocalMusicFiles() {
    $musicDir = '/var/www/cdn/music';
    $files = [];
    if (is_dir($musicDir)) {
        foreach (scandir($musicDir) as $file) {
            if (is_file("$musicDir/$file") && str_ends_with($file, '.mp3')) {
                $files[] = $file;
            }
        }
    }
    return $files;
}

// Fetch music files from local directory
$musicFiles = getLocalMusicFiles();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Overlay DMCA Music</title>
    <link rel="stylesheet" href="index.css">
    <script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
</head>
<body>
    <audio id="audio-player" preload="auto"></audio>
    <div id="now-playing"></div>
    <script>
        let socket;
        let currentSong = null;
        let currentSongData = null; // Store song data for replay
        let volume = 10;
        let playlist = <?php
            echo json_encode(array_map(function($f) {
                return [
                    'file' => $f,
                    'title' => preg_replace('/_/', ' ', preg_replace('/\.mp3$/', '', $f))
                ];
            }, $musicFiles));
        ?>;
        let currentIndex = 0;
        let repeat = false;
        let shuffle = false;
        let playedHistory = new Set();
        const audioPlayer = document.getElementById('audio-player');
        const nowPlayingDiv = document.getElementById('now-playing');
        const urlParams = new URLSearchParams(window.location.search);
        const showNowPlaying = urlParams.has('nowplaying');
        const color = urlParams.get('color') || 'white';

        if (showNowPlaying) {
            nowPlayingDiv.style.display = 'block';
            nowPlayingDiv.style.position = 'absolute';
            nowPlayingDiv.style.zIndex = '10000';
            nowPlayingDiv.style.fontSize = '24px';
            nowPlayingDiv.style.fontWeight = 'bold';
            let processedColor = color;
            if (!color.startsWith('#') && /^[0-9a-fA-F]{3,6}$/.test(color)) {
                processedColor = '#' + color;
            }
            nowPlayingDiv.style.color = processedColor;
            let textShadow = '2px 2px 4px rgba(0,0,0,0.8)';
            if (processedColor.toLowerCase() === 'black' || processedColor === '#000000' || processedColor === '#000000') {
                textShadow = '2px 2px 4px rgba(255,255,255,0.8)';
            }
            nowPlayingDiv.style.textShadow = textShadow;
            nowPlayingDiv.style.top = '50%';
            nowPlayingDiv.style.left = '50%';
            nowPlayingDiv.style.transform = 'translate(-50%, -50%)';
        } else {
            nowPlayingDiv.style.display = 'none';
        }

        function playSong(url, songData = null) {
            if (!url) return;
            currentSong = url;
            audioPlayer.src = url;
            audioPlayer.volume = (volume / 100) * 0.1;
            audioPlayer.play().then(() => {
                if (songData && songData.file) {
                    currentSongData = {
                        file: songData.file,
                        title: songData.title || songData.file.replace('.mp3','').replace(/_/g,' ')
                    };
                    playedHistory.add(songData.file);
                if (showNowPlaying) {
                    nowPlayingDiv.innerText = currentSongData.title;
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
            currentIndex = idx;
            const song = playlist[currentIndex];
            playSong(`https://cdn.botofthespecter.com/music/${encodeURIComponent(song.file)}`, song);
        }

        function playNextSong() {
            if (repeat) {
                audioPlayer.currentTime = 0;
                audioPlayer.play();
                return;
            }

            if (shuffle && playlist.length > 1) {
                let unplayed = playlist.filter(song => !playedHistory.has(song.file));
                if (unplayed.length === 0) {
                    playedHistory.clear();
                    unplayed = [...playlist];
                }
                const nextSong = unplayed[Math.floor(Math.random() * unplayed.length)];
                const nextIndex = playlist.findIndex(song => song.file === nextSong.file);
                playSongByIndex(nextIndex);
            } else {
                currentIndex = (currentIndex + 1) % playlist.length;
                playSongByIndex(currentIndex);
            }
        }

        function autoStartFirstSong() {
            if (playlist.length > 0) {
                playSongByIndex(0);
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

            // Log all events and their data to the browser console
            socket.onAny((event, ...args) => {
                console.log('Event:', event, ...args);
            });

            socket.on('connect', () => {
                const urlParams = new URLSearchParams(window.location.search);
                const code = urlParams.get('code');
                if (!code) return;
                socket.emit('REGISTER', { code: code, channel: 'Overlay', name: 'DMCA' });
                tryAutoStartFirstSong();
            });

            socket.on('disconnect', () => {
                setTimeout(connectWebSocket, 5000);
            });

            socket.on('connect_error', () => {
                setTimeout(connectWebSocket, 5000);
            });

            socket.on('SUCCESS', () => {
                socket.emit('MUSIC_COMMAND', { command: 'MUSIC_SETTINGS' });
            });

            socket.on('MUSIC_SETTINGS', (settings) => {
                if (typeof settings.volume !== 'undefined') {
                    volume = settings.volume;
                    audioPlayer.volume = (volume / 100) * 0.1;
                }
                if (typeof settings.repeat !== 'undefined') {
                    repeat = !!settings.repeat;
                }
                if (typeof settings.shuffle !== 'undefined') {
                    shuffle = !!settings.shuffle;
                }
            });

            socket.on('NOW_PLAYING', (data) => {
                if (data?.song?.file) {
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
                        currentIndex = (currentIndex - 1 + playlist.length) % playlist.length;
                        playSongByIndex(currentIndex);
                        break;
                    case 'play_index':
                        if (typeof data.index !== 'undefined') playSongByIndex(data.index);
                        break;
                    case 'MUSIC_SETTINGS':
                        if (typeof data.volume !== 'undefined') {
                            volume = data.volume;
                            audioPlayer.volume = (volume / 100) * 0.1;
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

        document.body.addEventListener('click', () => {
            audioPlayer.play().catch(() => {});
        }, { once: true });

        connectWebSocket();
    </script>
</body>
</html>