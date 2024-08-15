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
    <title>WebSocket Notifications & Overlay System for BotOfTheSpecter</title>
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

            function updateWeatherOverlay(weather) {
                console.log('Updating weather overlay with data:', weather);
                const weatherOverlay = document.getElementById('weatherOverlay');
                weatherOverlay.innerHTML = `
                    <div class="overlay-content">
                        <div class="overlay-header">
                            <div id="currentTime" class="time"></div>
                            <div class="temperature">${weather.temperature}</div>
                        </div>
                        <div class="weather-details">
                            <img src="${weather.icon}" alt="${weather.status}" class="weather-icon">
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
                socket.emit('REGISTER', { code: code });
            });

            socket.on('disconnect', () => {
                console.log('Disconnected from WebSocket server');
            });

            socket.on('WELCOME', (data) => {
                console.log('Server says:', data.message);
            });

            socket.on('NOTIFY', (data) => {
                console.log('Notification:', data);
                alert(data.message);
            });

            socket.on('TTS', (data) => {
                console.log('TTS Audio file path:', data.audio_file);
                const audio = new Audio(data.audio_file);
                audio.volume = 0.8;
                audio.autoplay = true;
                audio.addEventListener('canplaythrough', () => {
                    console.log('Audio can play through without buffering');
                });
                audio.addEventListener('error', (e) => {
                    console.error('Error occurred while loading the audio file:', e);
                    alert('Failed to load audio file');
                });

                setTimeout(() => {
                    audio.play().catch(error => {
                        console.error('Error playing audio:', error);
                        alert('Click to play audio');
                    });
                }, 100);
            });

            socket.on('WALKON', (data) => {
                console.log('Walkon:', data);
                const audioFile = `https://walkons.botofthespecter.com/${data.channel}/${data.user}.mp3`;
                const audio = new Audio(audioFile);
                audio.volume = 0.8;
                audio.autoplay = true;
                audio.addEventListener('canplaythrough', () => {
                    console.log('Walkon audio can play through without buffering');
                });
                audio.addEventListener('error', (e) => {
                    console.error('Error occurred while loading the Walkon audio file:', e);
                    alert('Failed to load Walkon audio file');
                });

                setTimeout(() => {
                    audio.play().catch(error => {
                        console.error('Error playing Walkon audio:', error);
                        alert('Click to play Walkon audio');
                    });
                }, 100);
            });

            socket.on('DEATHS', (data) => {
                console.log('Death:', data);
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

            socket.on('WEATHER', async (data) => {
                console.log('Weather update received:', data);
                if (data.location) {
                    const weather = await getWeather(data.location);
                    if (weather) {
                        updateWeatherOverlay(weather);
                    }
                } else {
                    console.error('No location provided in WEATHER event data');
                }
            });

            // Listen for DISCORD_JOIN events
            socket.on('DISCORD_JOIN', (data) => {
                console.log('Discord Join:', data);
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
        });
    </script>
</head>
<body>
    <div id="deathOverlay" class="death-overlay"></div>
    <div id="weatherOverlay" class="weather-overlay hide"></div>
    <div id="discordOverlay" class="discord-overlay"></div>
</body>
</html>