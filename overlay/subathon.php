<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subathon Notifications</title>
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let socket;
            const retryInterval = 5000;
            let reconnectAttempts = 0;
            let countdownInterval = null;
            // Absolute target end (Unix ms) - ticking against this avoids setInterval drift.
            let endTimestampMs = 0;
            // While paused, the bot tells us how many seconds were left; the overlay holds
            // that locally so reads after pause show the frozen remainder.
            let pausedRemainingSeconds = 0;
            let timerRunning = false;
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            const subathonOverlay = document.getElementById('subathonOverlay');

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

            if (!code) {
                showOverlayError('No code provided in the URL', 'danger');
                return;
            }

            function connectWebSocket() {
                socket = io('wss://websocket.botofthespecter.com', {
                    reconnection: false
                });

                socket.on('connect', () => {
                    console.log('Connected to WebSocket server');
                    reconnectAttempts = 0;
                    socket.emit('REGISTER', { code: code, channel:'Overlay', name: 'Subathon' });
                });

                socket.on('disconnect', () => {
                    console.log('Disconnected from WebSocket server');
                    attemptReconnect();
                });

                socket.on('connect_error', (error) => {
                    console.error('Connection error:', error);
                    attemptReconnect();
                });

                socket.on('SUBATHON_START', (data) => {
                    console.log('SUBATHON_START event received:', data);
                    const end = Number(data.end_timestamp_ms);
                    if (Number.isFinite(end) && end > 0) {
                        endTimestampMs = end;
                    } else {
                        const minutes = Number(data.starting_minutes) || 0;
                        endTimestampMs = Date.now() + minutes * 60000;
                    }
                    pausedRemainingSeconds = 0;
                    startCountdown();
                    displaySubathonNotification('Subathon Started');
                });

                socket.on('SUBATHON_STOP', (data) => {
                    console.log('SUBATHON_STOP event received:', data);
                    stopCountdown();
                    displaySubathonNotification('Subathon Stopped');
                });

                socket.on('SUBATHON_PAUSE', (data) => {
                    console.log('SUBATHON_PAUSE event received:', data);
                    const incomingSeconds = Number(data.remaining_seconds);
                    if (Number.isFinite(incomingSeconds) && incomingSeconds >= 0) {
                        pausedRemainingSeconds = incomingSeconds;
                    } else {
                        const incomingMinutes = Number(data.remaining_minutes);
                        pausedRemainingSeconds = Number.isFinite(incomingMinutes) && incomingMinutes >= 0
                            ? incomingMinutes * 60
                            : Math.max(0, Math.floor((endTimestampMs - Date.now()) / 1000));
                    }
                    pauseCountdown();
                    displaySubathonNotification('Subathon Paused');
                });

                socket.on('SUBATHON_RESUME', (data) => {
                    console.log('SUBATHON_RESUME event received:', data);
                    const end = Number(data.end_timestamp_ms);
                    if (Number.isFinite(end) && end > 0) {
                        endTimestampMs = end;
                    } else {
                        const incomingSeconds = Number(data.remaining_seconds);
                        const incomingMinutes = Number(data.remaining_minutes);
                        const secs = Number.isFinite(incomingSeconds) && incomingSeconds >= 0
                            ? incomingSeconds
                            : (Number.isFinite(incomingMinutes) && incomingMinutes >= 0 ? incomingMinutes * 60 : pausedRemainingSeconds);
                        endTimestampMs = Date.now() + secs * 1000;
                    }
                    pausedRemainingSeconds = 0;
                    resumeCountdown();
                    displaySubathonNotification('Subathon Resumed');
                });

                socket.on('SUBATHON_ADD_TIME', (data) => {
                    console.log('SUBATHON_ADD_TIME event received:', data);
                    const end = Number(data.end_timestamp_ms);
                    if (Number.isFinite(end) && end > 0) {
                        endTimestampMs = end;
                    } else {
                        const minutes = Number(data.added_minutes) || 0;
                        endTimestampMs += minutes * 60000;
                    }
                    updateTimerDisplay();
                    displaySubathonNotification('Time Added');
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
                    console.log(`[onAny] Event: ${event}`, ...args);
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

            function displaySubathonNotification(message) {
                subathonOverlay.innerHTML = `<div>${message}</div>`;
                subathonOverlay.classList.add('show');
                subathonOverlay.style.display = 'block';
                updateTimerDisplay(); // Update timer display immediately

                setTimeout(() => {
                    subathonOverlay.classList.remove('show');
                    subathonOverlay.style.display = 'none';
                }, 10000);
            }

            function startCountdown() {
                timerRunning = true;
                subathonOverlay.style.display = 'block';
                if (countdownInterval !== null) {
                    clearInterval(countdownInterval);
                }
                updateTimerDisplay();
                countdownInterval = setInterval(() => {
                    if (getRemainingSeconds() <= 0) {
                        stopCountdown();
                        return;
                    }
                    updateTimerDisplay();
                }, 1000);
            }

            function pauseCountdown() {
                if (countdownInterval !== null) {
                    clearInterval(countdownInterval);
                    countdownInterval = null;
                }
                timerRunning = false;
                updateTimerDisplay();
            }

            function resumeCountdown() {
                if (!timerRunning) {
                    startCountdown();
                }
            }

            function stopCountdown() {
                if (countdownInterval !== null) {
                    clearInterval(countdownInterval);
                    countdownInterval = null;
                }
                timerRunning = false;
                endTimestampMs = 0;
                pausedRemainingSeconds = 0;
                subathonOverlay.style.display = 'none';
            }

            function getRemainingSeconds() {
                if (!timerRunning) {
                    return pausedRemainingSeconds;
                }
                return Math.max(0, Math.floor((endTimestampMs - Date.now()) / 1000));
            }

            function updateTimerDisplay() {
                const totalSeconds = getRemainingSeconds();
                if (totalSeconds <= 0 && !pausedRemainingSeconds) {
                    subathonOverlay.style.display = 'none';
                    return;
                }
                const days = Math.floor(totalSeconds / 86400);
                const hours = Math.floor((totalSeconds % 86400) / 3600);
                const minutes = Math.floor((totalSeconds % 3600) / 60);
                const seconds = totalSeconds % 60;
                subathonOverlay.innerHTML = `<div>${days} day(s), ${hours} hour(s), ${minutes} minute(s), ${seconds} second(s)</div>`;
                subathonOverlay.style.display = 'block';
            }

            // Start initial connection
            connectWebSocket();
        });
    </script>
</head>
<body>
    <div id="subathonOverlay" class="subathon-overlay-page hide"></div>
</body>
</html>