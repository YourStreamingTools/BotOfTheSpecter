<?php
include '/var/www/config/database.php';
$primary_db_name = 'website';

$conn = new mysqli($db_servername, $db_username, $db_password, $primary_db_name);
$api_key = $_GET['code'] ?? '';

$stmt = $conn->prepare("SELECT username FROM users WHERE api_key = ?");
$stmt->bind_param("s", $api_key);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$username = $user['username'] ?? '';
if ($username) {
    $db = new PDO("mysql:host=$db_servername;dbname=$username", $db_username, $db_password);
    $stmt = $db->prepare("SELECT * FROM profile");
    $stmt->execute();
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    $timezone = $profile['timezone'] ?? null;
} else {
    $timezone = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket Notifications & Overlay System for BotOfTheSpecter</title>
    <link rel="stylesheet" href="index.css">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let socket;
            const retryInterval = 5000;
            let reconnectAttempts = 0;
            let currentAudio = null;
            const audioQueue = [];
            const timezone = <?php echo json_encode($timezone); ?>;
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            if (!code) {
                alert('No code provided in the URL');
                return;
            }

            function enqueueAudio(url) {
                audioQueue.push(url);
                if (!currentAudio) {
                    playNextAudio();
                }
            }

            function playNextAudio() {
                if (audioQueue.length === 0) {
                    currentAudio = null;
                    return;
                }

                const url = audioQueue.shift();
                currentAudio = new Audio(`${url}?t=${new Date().getTime()}`);
                currentAudio.volume = 0.8;

                currentAudio.addEventListener('canplaythrough', () => {
                    console.log('Audio can play through without buffering');
                });

                currentAudio.addEventListener('ended', () => {
                    currentAudio = null;
                    playNextAudio();
                });

                currentAudio.addEventListener('error', (e) => {
                    console.error('Error occurred while loading the audio file:', e);
                    alert('Failed to load audio file');
                    currentAudio = null;
                    playNextAudio();
                });

                currentAudio.play().catch(error => {
                    console.error('Error playing audio:', error);
                    alert('Click to play audio');
                });
            }

            function connectWebSocket() {
                socket = io('wss://websocket.botofthespecter.com', {
                    reconnection: false
                });

                socket.on('connect', () => {
                    console.log('Connected to WebSocket server');
                    reconnectAttempts = 0;
                    socket.emit('REGISTER', { code: code, channel:'Overlay', name: 'Everything' });
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

                socket.on('NOTIFY', (data) => {
                    console.log('Notification:', data);
                    alert(data.message);
                });

                socket.on('TTS', (data) => {
                    console.log('TTS event received:', data);
                    enqueueAudio(data.audio_file);
                });

                socket.on('WALKON', (data) => {
                    console.log('WALKON event received:', data);
                    const audioFile = `https://walkons.botofthespecter.com/${data.channel}/${data.user}.mp3`;
                    enqueueAudio(audioFile);
                });

                socket.on('SOUND_ALERT', (data) => {
                    console.log('SOUND_ALERT event received:', data);
                    enqueueAudio(data.sound);
                });

                socket.on('DEATHS', (data) => {
                    console.log('DEATHS event received:', data);
                    const deathOverlay = document.getElementById('deathOverlay');
                    deathOverlay.innerHTML = `
                        <div class="overlay-content">
                            <div class="overlay-title">
                                <span class="overlay-emote"></span>
                                <span>Current Deaths</span>
                            </div>
                            <div>${data.game}</div>
                            <div>${data['death-text']}</div>
                        </div>
                    `;
                    deathOverlay.classList.remove('hide');
                    deathOverlay.classList.add('show');
                    deathOverlay.style.display = 'block';

                    setTimeout(() => {
                        deathOverlay.classList.remove('show');
                        deathOverlay.classList.add('hide');
                    }, 10000);

                    setTimeout(() => {
                        deathOverlay.style.display = 'none';
                    }, 11000);
                });

                // Listen for WEATHER_DATA events
                socket.on('WEATHER_DATA', (data) => {
                    console.log('WEATHER_DATA event received:', data);
                    const weather_data_fixed = data.weather_data.replace(/'/g, '"');
                    const weather = JSON.parse(weather_data_fixed);
                    const location = weather.location;
                    updateWeatherOverlay(weather, location);
                });

                // Listen for DISCORD_JOIN events
                socket.on('DISCORD_JOIN', (data) => {
                    console.log('DISCORD_JOIN event received:', data);
                    const discordOverlay = document.getElementById('discordOverlay');
                    discordOverlay.innerHTML = `
                        <div class="overlay-content">
                            <span>
                                <img src="https://cdn.jsdelivr.net/npm/simple-icons@v6/icons/discord.svg" alt="Discord Icon" class="discord-icon"> 
                                ${data.member} has joined the Discord server
                            </span>
                        </div>
                    `;
                    discordOverlay.classList.add('show');
                    discordOverlay.style.display = 'block';

                    // Display for 10 seconds
                    setTimeout(() => {
                        discordOverlay.classList.remove('show');
                        discordOverlay.classList.add('hide');
                    }, 10000);

                    // Hide after the transition
                    setTimeout(() => {
                        discordOverlay.style.display = 'none';
                    }, 11000);
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

            // Function to update weather overlay
            function updateWeatherOverlay(weather, location) {
                console.log('Updating weather overlay with data:', weather);
                const weatherOverlay = document.getElementById('weatherOverlay');
                weatherOverlay.innerHTML = `
                    <div class="overlay-content">
                        <div class="overlay-header">
                            <div id="currentTime" class="time"></div>
                            <div class="location">${location}</div>
                            <div class="temperature">${weather.temperature}</div>
                        </div>
                        <div class="weather-details">
                            <img src="${weather.icon}" alt="${weather.status}" class="weather-icon">
                            <div class="status">${weather.status}</div>
                            <div class="wind">${weather.wind}</div>
                            <div class="humidity">${weather.humidity}</div>
                        </div>
                    </div>
                `;
                weatherOverlay.classList.add('show');
                weatherOverlay.style.display = 'block';

                // Start the timer for updating the time
                startTimer(timezone);

                setTimeout(() => {
                    weatherOverlay.classList.add('hide');
                    weatherOverlay.classList.remove('show');
                }, 10000);

                setTimeout(() => {
                    weatherOverlay.style.display = 'none';
                }, 11000);
            }

            // Function to start the timer for current time
            function startTimer(timezone) {
                function updateTime() {
                    const currentTimeElement = document.getElementById('currentTime');
                    if (currentTimeElement) {
                        currentTimeElement.innerHTML = new Date().toLocaleTimeString('en-US', { timeZone: timezone, hour: '2-digit', minute: '2-digit', second: '2-digit' });
                    }
                }
                updateTime();
                setInterval(updateTime, 1000);
            }

            // Start initial connection
            connectWebSocket();
        });
    </script>
</head>
<body>
    <div id="deathOverlay" class="death-overlay"></div>
    <div id="weatherOverlay" class="weather-overlay hide"></div>
    <div id="discordOverlay" class="discord-overlay"></div>
</body>
</html>