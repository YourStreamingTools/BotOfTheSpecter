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
    $user_conn = new mysqli($db_servername, $db_username, $db_password, $username);
    if ($user_conn->connect_error) {
        $timezone = null;
    } else {
        $profile_result = $user_conn->query("SELECT timezone FROM profile LIMIT 1");
        if ($profile_result && $profile_row = $profile_result->fetch_assoc()) {
            $timezone = $profile_row['timezone'] ?? null;
        } else {
            $timezone = null;
        }
        $user_conn->close();
    }
} else {
    $timezone = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WebSocket Weather Notifications</title>
    <link rel="stylesheet" href="index.css">
    <script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let socket;
            const retryInterval = 5000;
            let reconnectAttempts = 0;
            const timezone = <?php echo json_encode($timezone); ?>;
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            if (!code) {
                alert('No code provided in the URL');
                return;
            }

            function connectWebSocket() {
                socket = io('wss://websocket.botofthespecter.com', {
                    reconnection: false
                });

                socket.on('connect', () => {
                    console.log('Connected to WebSocket server');
                    reconnectAttempts = 0;
                    socket.emit('REGISTER', { code: code, channel:'Overlay', name: 'Weather' });
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

                socket.on('WEATHER_DATA', (data) => {
                    console.log('WEATHER_DATA event received:', data);
                    const weather_data_fixed = data.weather_data.replace(/'/g, '"');
                    const weather = JSON.parse(weather_data_fixed);
                    const location = weather.location;
                    updateWeatherOverlay(weather, location);
                });

                // Log all events
                socket.onAny((event, ...args) => {
                    console.log(`Event: ${event}`, args);
                });
            }

            function attemptReconnect() {
                reconnectAttempts++;
                const delay = Math.min(retryInterval * reconnectAttempts, 30000);
                console.log(`Attempting to reconnect in ${delay / 1000} seconds...`);
                setTimeout(() => {
                    connectWebSocket();
                }, delay);
            }

            function updateWeatherOverlay(weather, location) {
                console.log('Updating weather overlay with data:', weather);
                const weatherOverlay = document.getElementById('weatherOverlay');
                weatherOverlay.innerHTML = `
                    <div class="overlay-content">
                        <div class="overlay-header">
                            ${timezone ? '<div id="currentTime" class="time"></div>' : ''}
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
                if (timezone) {
                    startTimer(timezone);
                }

                setTimeout(() => {
                    weatherOverlay.classList.add('hide');
                    weatherOverlay.classList.remove('show');
                }, 10000);

                setTimeout(() => {
                    weatherOverlay.style.display = 'none';
                }, 11000);
            }

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
    <div id="weatherOverlay" class="weather-overlay hide"></div>
</body>
</html>