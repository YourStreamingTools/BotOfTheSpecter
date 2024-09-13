<?php
$db_servername = 'sql.botofthespecter.com';
$db_username = ''; // CHANGE TO MAKE THIS WORK
$db_password = ''; // CHANGE TO MAKE THIS WORK
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
    <title>WebSocket Weather Notifications</title>
    <link rel="stylesheet" href="index.css">
    <script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const socket = io('wss://websocket.botofthespecter.com');
            const timezone = <?php echo json_encode($timezone); ?>;
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            if (!code) {
                alert('No code provided in the URL');
                return;
            }

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

            socket.on('connect', () => {
                console.log('Connected to WebSocket server');
                socket.emit('REGISTER', { code: code, name: 'Weather Overlay' });
            });

            socket.on('disconnect', () => {
                console.log('Disconnected from WebSocket server');
            });

            socket.on('WELCOME', (data) => {
                console.log('Server says:', data.message);
            });

            socket.on('WEATHER_DATA', (data) => {
                console.log('Weather update received:', data);
                updateWeatherOverlay(data);
            });
        });
    </script>
</head>
<body>
    <div id="weatherOverlay" class="weather-overlay hide"></div>
</body>
</html>