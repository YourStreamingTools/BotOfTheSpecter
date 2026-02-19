<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subathon Notifications</title>
    <link rel="stylesheet" href="index.css">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let socket;
            const retryInterval = 5000;
            let reconnectAttempts = 0;
            let countdownInterval;
            let totalTime = 0; // Total time for the subathon in minutes
            let remainingTime = 0; // Remaining time for the countdown in minutes
            let timerRunning = false;
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            const subathonOverlay = document.getElementById('subathonOverlay');

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
                    totalTime = Number(data.starting_minutes) || 0;
                    remainingTime = totalTime;
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
                    const incomingRemaining = Number(data.remaining_minutes);
                    if (!Number.isNaN(incomingRemaining) && incomingRemaining >= 0) {
                        remainingTime = incomingRemaining;
                    }
                    pauseCountdown();
                    displaySubathonNotification('Subathon Paused');
                });

                socket.on('SUBATHON_RESUME', (data) => {
                    console.log('SUBATHON_RESUME event received:', data);
                    const incomingRemaining = Number(data.remaining_minutes);
                    if (!Number.isNaN(incomingRemaining) && incomingRemaining >= 0) {
                        remainingTime = incomingRemaining;
                    }
                    resumeCountdown();
                    displaySubathonNotification('Subathon Resumed');
                });

                socket.on('SUBATHON_ADD_TIME', (data) => {
                    console.log('SUBATHON_ADD_TIME event received:', data);
                    addTime(Number(data.added_minutes) || 0);
                    displaySubathonNotification('Time Added');
                });

                // Log all events
                socket.onAny((event, ...args) => {
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
                subathonOverlay.style.display = 'block'; // Show the overlay

                countdownInterval = setInterval(() => {
                    if (remainingTime > 0) {
                        remainingTime -= 1; // Decrease by 1 minute
                        updateTimerDisplay();
                    } else {
                        stopCountdown(); // Stop when the countdown reaches zero
                    }
                }, 60000); // Update every minute
            }

            function pauseCountdown() {
                clearInterval(countdownInterval);
                timerRunning = false; // Stop the timer but keep overlay visible
            }

            function resumeCountdown() {
                if (!timerRunning) {
                    startCountdown(); // Restart the countdown
                }
            }

            function stopCountdown() {
                clearInterval(countdownInterval);
                countdownInterval = null;
                timerRunning = false;
                remainingTime = 0; // Reset remaining time
                subathonOverlay.style.display = 'none'; // Hide the overlay
            }

            function addTime(additionalTime) {
                remainingTime += additionalTime; // Add minutes directly
                updateTimerDisplay();
            }

            function updateTimerDisplay() {
                if (remainingTime > 0) {
                    const days = Math.floor(remainingTime / 1440); // 1440 minutes in a day
                    const hours = Math.floor((remainingTime % 1440) / 60);
                    const minutes = remainingTime % 60;
                    const seconds = 0; // As the timer counts down by minutes, we set seconds to 0

                    subathonOverlay.innerHTML = `<div>${days} day(s), ${hours} hour(s), ${minutes} minute(s), ${seconds} second(s)</div>`;
                    subathonOverlay.style.display = 'block'; // Ensure overlay is visible
                } else {
                    subathonOverlay.style.display = 'none'; // Hide the overlay if no time remains
                }
            }

            // Start initial connection
            connectWebSocket();
        });
    </script>
</head>
<body>
    <div id="subathonOverlay" class="subathon-overlay hide"></div>
</body>
</html>