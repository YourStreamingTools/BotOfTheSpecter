<?php
include '/var/www/config/cloudflare.php';

// S3/R2 PHP playlist fetch logic
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
    <script>
        let socket;
        let currentSong = null;
        let currentSongData = null; // Store song data for replay
        let volume = 80;
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

        function playSong(url, songData = null) {
            if (!url) return;
            currentSong = url;
            audioPlayer.src = url;
            audioPlayer.volume = volume / 100;
            audioPlayer.play().then(() => {
                if (songData && songData.file) {
                    currentSongData = {
                        file: songData.file,
                        title: songData.title || songData.file.replace('.mp3','').replace(/_/g,' ')
                    };
                    playedHistory.add(songData.file);
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
                    audioPlayer.volume = volume / 100;
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
                            audioPlayer.volume = volume / 100;
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