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
            const apiKey = ''; // CHANGE TO MAKE THIS WORK
            const timezone = <?php echo json_encode($timezone); ?>;
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            if (!code) {
                alert('No code provided in the URL');
                return;
            }

            async function getLatLon(location) {
                console.log(`Fetching coordinates for location: ${location}`);
                const response = await fetch(`https://api.openweathermap.org/geo/1.0/direct?q=${location}&limit=1&appid=${apiKey}`);
                const data = await response.json();
                if (data.length > 0) {
                    console.log(`Coordinates for ${location}:`, data[0]);
                    return { lat: data[0].lat, lon: data[0].lon };
                }
                console.error(`Coordinates not found for location: ${location}`);
                return null;
            }

            async function fetchWeatherData(lat, lon, units = 'metric') {
                console.log(`Fetching weather data for coordinates: (${lat}, ${lon}), units: ${units}`);
                const response = await fetch(`https://api.openweathermap.org/data/3.0/onecall?lat=${lat}&lon=${lon}&exclude=minutely,hourly,daily,alerts&units=${units}&appid=${apiKey}`);
                const data = await response.json();
                console.log(`Weather data:`, data);
                return data;
            }

            async function getWeather(location) {
                const coords = await getLatLon(location);
                if (!coords) {
                    return null;
                }
                const weatherDataMetric = await fetchWeatherData(coords.lat, coords.lon, 'metric');
                const weatherDataImperial = await fetchWeatherData(coords.lat, coords.lon, 'imperial');
                if (!weatherDataMetric || !weatherDataImperial) {
                    return null;
                }
                return formatWeatherData(weatherDataMetric.current, weatherDataImperial.current);
            }

            function formatWeatherData(currentWeatherMetric, currentWeatherImperial) {
                const status = currentWeatherMetric.weather[0].description;
                const temperatureC = currentWeatherMetric.temp.toFixed(1);
                const temperatureF = currentWeatherImperial.temp.toFixed(1);
                const windSpeedKph = currentWeatherMetric.wind_speed.toFixed(1);
                const windSpeedMph = currentWeatherImperial.wind_speed.toFixed(1);
                const humidity = currentWeatherMetric.humidity;
                const windDirection = getWindDirection(currentWeatherMetric.wind_deg);
                const weatherIcon = `https://openweathermap.org/img/wn/${currentWeatherMetric.weather[0].icon}@2x.png`;
                return {
                    status,
                    temperature: `${temperatureC}°C | ${temperatureF}°F`,
                    wind: `${windSpeedKph} km/h | ${windSpeedMph} mph ${windDirection}`,
                    humidity: `Humidity: ${humidity}%`,
                    icon: weatherIcon
                };
            }

            function getWindDirection(deg) {
                const cardinalDirections = {
                    'N': [337.5, 22.5],
                    'NE': [22.5, 67.5],
                    'E': [67.5, 112.5],
                    'SE': [112.5, 157.5],
                    'S': [157.5, 202.5],
                    'SW': [202.5, 247.5],
                    'W': [247.5, 292.5],
                    'NW': [292.5, 337.5]
                };
                for (const direction in cardinalDirections) {
                    const [start, end] = cardinalDirections[direction];
                    if (deg >= start && deg < end) {
                        return direction;
                    }
                }
                return 'N/A';
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

            socket.onAny((event, ...args) => {
                console.log(`Event: ${event}`, args);
            });

            socket.on('WEATHER', async (data) => {
                console.log('Weather update received:', data);
                if (data.location) {
                    const weather = await getWeather(data.location);
                    if (weather) {
                        updateWeatherOverlay(weather, data.location);
                    }
                } else {
                    console.error('No location provided in WEATHER event data');
                }
            });
        });
    </script>
</head>
<body>
    <div id="weatherOverlay" class="weather-overlay hide"></div>
</body>
</html>