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

            async function fetchWeatherData(lat, lon) {
                const response = await fetch(`https://api.openweathermap.org/data/3.0/onecall?lat=${lat}&lon=${lon}&exclude=minutely,hourly,daily,alerts&units=metric&appid=${apiKey}`);
                const data = await response.json();
                return data;
            }

            async function getWeather(location) {
                const coords = await getLatLon(location);
                if (!coords) {
                    return null;
                }
                const weatherData = await fetchWeatherData(coords.lat, coords.lon);
                if (!weatherData) {
                    return null;
                }
                return formatWeatherData(location, weatherData.current);
            }

            function formatWeatherData(location, currentWeather) {
                const status = currentWeather.weather[0].description;
                const temperature = currentWeather.temp;
                const temperatureF = (temperature * 9 / 5 + 32).toFixed(1);
                const windSpeed = currentWeather.wind_speed.toFixed(1);
                const windSpeedMph = (windSpeed / 1.6).toFixed(2);
                const humidity = currentWeather.humidity;
                const windDirection = getWindDirection(currentWeather.wind_deg);

                return {
                    location,
                    status,
                    temperature: `${temperature}°C (${temperatureF}°F)`,
                    wind: `Wind: ${windDirection} at ${windSpeed} kph (${windSpeedMph} mph)`,
                    humidity: `Humidity: ${humidity}%`
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
                        <div class="overlay-title">
                            <span class="overlay-emote"></span>
                            <span>Current Weather</span>
                        </div>
                        <div>${weather.location}</div>
                        <div>${weather.temperature}</div>
                        <div>${weather.status}</div>
                        <div>${weather.wind}</div>
                        <div>${weather.humidity}</div>
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
        .weather-overlay .overlay-title {
            font-size: 18px;
            font-weight: bold;
            display: flex;
            align-items: center;
        }
        .weather-overlay .overlay-emote {
            margin-right: 10px;
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