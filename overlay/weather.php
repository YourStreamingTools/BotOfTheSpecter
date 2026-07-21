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
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let socket;
            const retryInterval = 5000;
            let reconnectAttempts = 0;
            const timezone = <?php echo json_encode($timezone); ?>;
            let timerIntervalId = null;
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            function showOverlayError(message, type) {
                let banner = document.getElementById('overlayErrorBanner');
                if (!banner) {
                    banner = document.createElement('div');
                    banner.id = 'overlayErrorBanner';
                    document.body.appendChild(banner);
                }
                banner.textContent = message;
                banner.className = 'overlay-error-banner ' + (type === 'warn' ? 'overlay-error-banner-warn' : 'overlay-error-banner-danger');
                banner.style.display = 'block';
                if (type === 'warn') {
                    clearTimeout(banner._timeoutId);
                    banner._timeoutId = setTimeout(() => { banner.style.display = 'none'; }, 6000);
                }
            }

            function setConnectionStatus(text, state) {
                let status = document.getElementById('overlayConnectionStatus');
                if (!status) {
                    status = document.createElement('div');
                    status.id = 'overlayConnectionStatus';
                    status.className = 'overlay-connection-status';
                    document.body.appendChild(status);
                }
                status.textContent = text;
                status.dataset.state = state;
            }

            const username = <?php echo json_encode($username); ?>;

            if (!code) {
                showOverlayError('No code provided in the URL', 'danger');
                return;
            }
            if (!username) {
                showOverlayError('Invalid code provided in the URL', 'danger');
                return;
            }

            function escapeHtml(str) {
                return String(str ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function connectWebSocket() {
                setConnectionStatus('Connecting…', 'connecting');
                socket = io('wss://websocket.botofthespecter.com', {
                    reconnection: false
                });

                socket.on('connect', () => {
                    console.log('Connected to WebSocket server');
                    setConnectionStatus('Connected', 'connected');
                    reconnectAttempts = 0;
                    socket.emit('REGISTER', { code: code, channel:'Overlay', name: 'Weather' });
                });

                socket.on('disconnect', () => {
                    console.log('Disconnected from WebSocket server');
                    setConnectionStatus('Disconnected', 'error');
                    attemptReconnect();
                });

                socket.on('connect_error', (error) => {
                    console.error('Connection error:', error);
                    setConnectionStatus('Connection error', 'error');
                    attemptReconnect();
                });

                socket.on('WELCOME', (data) => {
                    console.log('Server says:', data.message);
                });

                socket.on('WEATHER_DATA', (data) => {
                    console.log('WEATHER_DATA event received:', data);
                    let weather;
                    try {
                        weather = JSON.parse(data.weather_data);
                    } catch (_) {
                        // Legacy payload: api.py used to send str(dict) via urlencode.
                        weather = JSON.parse(data.weather_data.replace(/'/g, '"'));
                    }
                    updateWeatherOverlay(weather, weather.location);
                });

                // Dashboard "Refresh Overlay" - full page reload so PHP re-fetches settings.
                socket.on('OVERLAY_REFRESH', (data) => {
                    console.log('OVERLAY_REFRESH received - reloading', data);
                    const meta = document.createElement('meta');
                    meta.setAttribute('http-equiv', 'refresh');
                    meta.setAttribute('content', '0');
                    document.head.appendChild(meta);
                });

                // Log all events
                socket.onAny((event, ...args) => {
                    if (event.startsWith('CLOSED_CAPTION')) return;
                    console.log(`Event: ${event}`, args);
                });
            }

            function attemptReconnect() {
                reconnectAttempts++;
                const delay = Math.min(retryInterval * reconnectAttempts, 30000);
                console.log(`Attempting to reconnect in ${delay / 1000} seconds...`);
                setConnectionStatus('Reconnecting…', 'connecting');
                setTimeout(() => {
                    connectWebSocket();
                }, delay);
            }

            function updateWeatherOverlay(weather, location) {
                console.log('Updating weather overlay with data:', weather);
                const weatherOverlay = document.getElementById('weatherOverlay');
                weatherOverlay.innerHTML = `
                    <div class="weather-overlay-page-content">
                        <div class="weather-overlay-page-header">
                            ${timezone ? '<div id="currentTime" class="weather-overlay-page-time"></div>' : ''}
                            <div class="weather-overlay-page-location">${escapeHtml(location)}</div>
                            <div class="weather-overlay-page-temperature">${escapeHtml(weather.temperature)}</div>
                        </div>
                        <div class="weather-overlay-page-details">
                            <img src="${escapeHtml(weather.icon)}" alt="${escapeHtml(weather.status)}" class="weather-overlay-page-icon">
                            <div class="weather-overlay-page-status">${escapeHtml(weather.status)}</div>
                            <div class="weather-overlay-page-wind">${escapeHtml(weather.wind)}</div>
                            <div class="weather-overlay-page-humidity">${escapeHtml(weather.humidity)}</div>
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
                if (timerIntervalId !== null) {
                    clearInterval(timerIntervalId);
                }
                updateTime();
                timerIntervalId = setInterval(updateTime, 1000);
            }

            // Start initial connection
            connectWebSocket();
        });
    </script>
</head>
<body>
    <div id="weatherOverlay" class="weather-overlay-page hide"></div>
</body>
</html>