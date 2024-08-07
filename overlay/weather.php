<?php
$db_servername = 'sql.botofthespecter.com';
$db_username = ''; // CHANGE TO MAKE THIS WORK
$db_password = ''; // CHANGE TO MAKE THIS WORK
$primary_db_name = 'website';

$conn = new mysqli($db_servername, $db_username, $db_password, $primary_db_name);
$api_key = $_GET['code'];

$stmt = $conn->prepare("SELECT username FROM users WHERE api_key = ?");
$stmt->bind_param("s", $api_key);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$username = $user['username'];

$db = new PDO("mysql:host=$db_servername;dbname=$username", $db_username, $db_password);
$stmt = $db->prepare("SELECT * FROM profile");
$stmt->execute();
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
$timezone = isset($profile['timezone']) ? $profile['timezone'] : null;
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
            const socket = io('wss://websocket.botofthespecter.com:8080');
            const apiKey = ''; // CHANGE TO MAKE THIS WORK
            const timezone = <?php echo json_encode($timezone); ?>;
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            if (!code) {
                alert('No code provided in the URL');
                return;
            }
            async function getLatLon(location) {
                const response = await fetch(`http://api.openweathermap.org/geo/1.0/direct?q=${location}&limit=1&appid=${apiKey}`);
                const data = await response.json();
                if (data.length > 0) {
                    return { lat: data[0].lat, lon: data[0].lon };
                }
                return null;
            }

            async function fetchWeatherData(lat, lon, units='metric') {
                const response = await fetch(`https://api.openweathermap.org/data/3.0/onecall?lat=${lat}&lon=${lon}&exclude=minutely,hourly,daily,alerts&units=${units}&appid=${apiKey}`);
                const data = await response.json();
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
                let currentTime = null;
                if (timezone) {
                    currentTime = new Date().toLocaleTimeString('en-US', { timeZone: timezone, hour: '2-digit', minute: '2-digit', second: '2-digit' });
                }
                return {
                    currentTime,
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

            function updateWeatherOverlay(weather) {
                const weatherOverlay = document.getElementById('weatherOverlay');
                weatherOverlay.innerHTML = `
                    <div class="overlay-content">
                        <div class="overlay-header">
                            ${weather.currentTime ? `<div class="time">${weather.currentTime}</div>` : ''}
                            <div class="temperature">${weather.temperature}</div>
                        </div>
                        <div class="weather-details">
                            <img src="${weather.icon}" alt="${weather.status}" class="weather-icon">
                            <div class="wind">${weather.wind}</div>
                            <div class="humidity">${weather.humidity}</div>
                        </div>
                    </div>
                `;
                weatherOverlay.classList.remove('hide');
                weatherOverlay.classList.add('show');
                weatherOverlay.style.display = 'block';

                setTimeout(() => {
                    weatherOverlay.classList.remove('show');
                    weatherOverlay.classList.add('hide');
                }, 10000);

                setTimeout(() => {
                    weatherOverlay.style.display = 'none';
                }, 11000);
            }

            socket.on('connect', () => {
                console.log('Connected to WebSocket server');
                socket.emit('REGISTER', { code: code });
            });

            socket.on('disconnect', () => {
                console.log('Disconnected from WebSocket server');
            });

            socket.on('WELCOME', (data) => {
                console.log('Server says:', data.message);
            });

            socket.on('WEATHER', async (data) => {
                console.log('Weather update:', data);
                const weather = await getWeather(data.city);
                if (weather) {
                    updateWeatherOverlay(weather);
                }
            });
        });
    </script>
    <style>
        .weather-overlay {
            position: absolute;
            top: 10px;
            left: 10px;
            background-color: rgba(255, 255, 255, 0.8);
            padding: 10px;
            border-radius: 5px;
            font-family: Arial, sans-serif;
            color: #333;
            max-width: 250px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .weather-overlay .overlay-content {
            font-size: 14px;
        }
        .weather-overlay .overlay-header {
            display: flex;
            justify-content: space-between;
            width: 100%;
        }
        .weather-overlay .time {
            font-size: 14px;
            color: #666;
        }
        .weather-overlay .temperature {
            font-size: 22px;
            margin-top: 5px;
        }
        .weather-overlay .condition {
            font-size: 16px;
            color: #666;
            margin-top: 5px;
        }
        .weather-overlay .wind, .weather-overlay .humidity {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        .weather-overlay .weather-details {
            display: flex;
            align-items: center;
            margin-top: 10px;
        }
        .weather-overlay .weather-icon {
            width: 50px;
            height: 50px;
            margin-right: 10px;
        }
        .weather-overlay.hide {
            display: none;
        }
        .weather-overlay.show {
            display: block;
        }
    </style>
</head>
<body>
    <div id="weatherOverlay" class="weather-overlay hide"></div>
</body>
</html>