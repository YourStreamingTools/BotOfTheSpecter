<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Overlay DMCA Music</title>
    <link rel="stylesheet" href="index.css">
    <script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
    <style>
        html, body { background: transparent !important; }
        body { margin: 0; padding: 0; }
    </style>
</head>
<body>
    <audio id="audio-player" preload="auto"></audio>
    <script>
        let socket;
        let currentSong = null;
        let currentSongData = null; // Store song data for replay
        let volume = 80;
        let ttsQueue = [];
        let ttsPlaying = false;

        const audioPlayer = document.getElementById('audio-player');
        const ttsPlayer = document.getElementById('tts-player');

        function connectWebSocket() {
            socket = io('wss://websocket.botofthespecter.com', { reconnection: false });

            socket.on('connect', () => {
                const urlParams = new URLSearchParams(window.location.search);
                const code = urlParams.get('code');
                if (!code) return;
                socket.emit('REGISTER', { code: code, channel: 'Overlay', name: 'DMCA' });
            });

            socket.on('disconnect', () => {
                setTimeout(connectWebSocket, 5000);
            });

            socket.on('connect_error', () => {
                setTimeout(connectWebSocket, 5000);
            });

            socket.on('SUCCESS', () => {
                socket.emit('MUSIC_COMMAND', { command: 'MUSIC_SETTINGS' });
                socket.emit('MUSIC_COMMAND', { command: 'WHAT_IS_PLAYING' });
            });

            socket.on('MUSIC_SETTINGS', (settings) => {
                if (settings && typeof settings.volume !== 'undefined') {
                    volume = settings.volume;
                    audioPlayer.volume = volume / 100;
                    ttsPlayer.volume = volume / 100;
                }
            });

            socket.on('NOW_PLAYING', (data) => {
                if (data && data.song && data.song.file) {
                    playSong(`https://cdn.botofthespecter.com/music/${encodeURIComponent(data.song.file)}`);
                    currentSongData = data.song; // Remember song data
                } else {
                    stopSong();
                    currentSongData = null;
                }
            });

            socket.on('PLAY', () => {
                if (currentSong) audioPlayer.play();
            });

            socket.on('PAUSE', () => {
                audioPlayer.pause();
            });

            socket.on('TTS', (data) => {
                if (data && data.audio_file) {
                    enqueueTTS(data.audio_file);
                }
            });

            // Respond to WHAT_IS_PLAYING with current song info
            socket.on('WHAT_IS_PLAYING', () => {
                if (currentSongData) {
                    socket.emit('NOW_PLAYING', { song: currentSongData });
                } else {
                    socket.emit('NOW_PLAYING', { song: null });
                }
            });
        }

        function playSong(url) {
            if (!url) return;
            currentSong = url;
            audioPlayer.src = url;
            audioPlayer.volume = volume / 100;
            audioPlayer.play().catch(()=>{});
        }

        function stopSong() {
            audioPlayer.pause();
            audioPlayer.currentTime = 0;
        }

        function enqueueTTS(url) {
            ttsQueue.push(url);
            if (!ttsPlaying) playNextTTS();
        }

        function playNextTTS() {
            if (ttsQueue.length === 0) {
                ttsPlaying = false;
                return;
            }
            ttsPlaying = true;
            const url = ttsQueue.shift();
            ttsPlayer.src = url;
            ttsPlayer.volume = volume / 100;
            ttsPlayer.play().catch(() => { ttsPlaying = false; });
        }

        ttsPlayer.addEventListener('ended', () => {
            playNextTTS();
        });

        document.body.addEventListener('click', () => {
            audioPlayer.play().catch(()=>{});
            ttsPlayer.play().catch(()=>{});
        }, { once: true });

        connectWebSocket();
    </script>
</body>
</html>