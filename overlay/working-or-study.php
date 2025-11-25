<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Specter Working/Study Timer</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
    <style>
        :root {
            --accent-color: #ff9161;
            --overlay-scale: 0.5;
            --timer-width: min(420px, 90vw);
        }
        * {
            box-sizing: border-box;
        }
        html,
        body {
            margin: 0;
            min-height: 100vh;
            background-color: transparent;
            background-image: none;
            font-family: "Inter", "Segoe UI", system-ui, sans-serif;
            color: #f8fbff;
        }
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100%;
        }
        .placeholder {
            display: none;
            font-size: 1rem;
            color: rgba(255, 255, 255, 0.8);
            background: rgba(255, 255, 255, 0.04);
            border-radius: 20px;
            padding: 16px 24px;
            text-align: center;
            width: min(420px, 90vw);
        }
        .timer-wrapper {
            width: var(--timer-width);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .timer-card {
            width: calc(var(--timer-width) / var(--overlay-scale));
            padding: calc(32px / var(--overlay-scale));
            border-radius: calc(28px / var(--overlay-scale));
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.03), rgba(4, 6, 11, 0.95));
            border: calc(1px / var(--overlay-scale)) solid rgba(255, 255, 255, 0.06);
            box-shadow: 0 calc(30px / var(--overlay-scale)) calc(65px / var(--overlay-scale)) rgba(0, 0, 0, 0.65);
            display: flex;
            flex-direction: column;
            gap: calc(20px / var(--overlay-scale));
            text-align: center;
            transform: scale(var(--overlay-scale));
            transform-origin: center center;
        }
        .timer-status {
            font-size: calc(0.95rem / var(--overlay-scale));
            letter-spacing: calc(0.25em / var(--overlay-scale));
            text-transform: uppercase;
            color: var(--accent-color);
        }
        .timer-display {
            font-size: clamp(calc(48px / var(--overlay-scale)), calc(8vw / var(--overlay-scale)), calc(72px / var(--overlay-scale)));
            font-weight: calc(600 / var(--overlay-scale));
            letter-spacing: calc(4px / var(--overlay-scale));
        }
        .status-chip {
            font-size: calc(0.95rem / var(--overlay-scale));
            padding: calc(6px / var(--overlay-scale)) calc(14px / var(--overlay-scale));
            border-radius: calc(999px / var(--overlay-scale));
            background: rgba(255, 255, 255, 0.08);
            display: inline-flex;
            align-items: center;
            gap: calc(6px / var(--overlay-scale));
            margin: 0 auto;
        }
    </style>
</head>
<body>
    <div class="placeholder" id="timerPlaceholder">Append <code>&timer</code> to the overlay URL to show the Specter timer.</div>
    <div class="timer-wrapper" id="timerWrapper">
        <div class="timer-card" id="timerCard">
        <div class="timer-status" id="phaseLabel">Focus sprint</div>
        <div class="status-chip" id="statusChip">Ready to focus</div>
        <div class="timer-display" id="timerDisplay">00:00</div>
        </div>
    </div>
    <script>
        (() => {
            const urlParams = new URLSearchParams(window.location.search);
            const parseMinutesParam = (value, fallback) => {
                if (value === undefined || value === null || value === '') return fallback;
                const numeric = Number(value);
                return Number.isFinite(numeric) && numeric > 0 ? numeric : fallback;
            };
            const minutesToSeconds = minutes => Math.max(1, Math.round(minutes * 60));
            const parseMinutesValue = value => {
                const numeric = Number(value);
                return Number.isFinite(numeric) && numeric > 0 ? minutesToSeconds(numeric) : null;
            };
            const focusSeconds = minutesToSeconds(parseMinutesParam(urlParams.get('focus_minutes'), 60));
            const microSeconds = minutesToSeconds(parseMinutesParam(urlParams.get('break_minutes'), 15));
            const rechargeSeconds = minutesToSeconds(parseMinutesParam(urlParams.get('recharge_minutes'), 15));
            const phases = {
                focus: { label: 'Focus sprint', duration: focusSeconds, status: 'Flow mode on', accent: '#ff9161' },
                micro: { label: 'Micro break', duration: microSeconds, status: 'Recharge quickly', accent: '#6be9ff' },
                recharge: { label: 'Recharge stretch', duration: rechargeSeconds, status: 'Stretch & hydrate', accent: '#b483ff' }
            };
            const defaultDurations = {
                focus: focusSeconds,
                micro: microSeconds,
                recharge: rechargeSeconds
            };
            let currentPhase = 'focus';
            let remainingSeconds = phases[currentPhase].duration;
            let countdownId = null;
            const phaseLabel = document.getElementById('phaseLabel');
            const statusChip = document.getElementById('statusChip');
            const timerDisplay = document.getElementById('timerDisplay');
            const formatTime = seconds => {
                const mins = Math.floor(seconds / 60);
                const secs = seconds % 60;
                return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
            };
            const updateDisplay = () => {
                phaseLabel.textContent = phases[currentPhase].label;
                statusChip.textContent = phases[currentPhase].status;
                timerDisplay.textContent = formatTime(remainingSeconds);
                document.documentElement.style.setProperty('--accent-color', phases[currentPhase].accent);
            };
            const clearCountdown = () => {
                if (countdownId) {
                    clearInterval(countdownId);
                    countdownId = null;
                }
            };
            const startCountdown = () => {
                clearCountdown();
                countdownId = setInterval(() => {
                    if (remainingSeconds <= 0) {
                        clearCountdown();
                        statusChip.textContent = 'Session complete — choose next phase';
                        return;
                    }
                    remainingSeconds -= 1;
                    updateDisplay();
                }, 1000);
                updateDisplay();
            };
            const updateDefaultDurationsFromPayload = payload => {
                if (!payload) return;
                const focusOverride = parseMinutesValue(payload.focus_minutes);
                const breakOverride = parseMinutesValue(payload.break_minutes);
                if (focusOverride) {
                    defaultDurations.focus = focusOverride;
                }
                if (breakOverride) {
                    defaultDurations.micro = breakOverride;
                    defaultDurations.recharge = breakOverride;
                }
            };

            const parseDurationOverride = payload => {
                if (!payload) return null;
                if (payload.duration_seconds !== undefined && payload.duration_seconds !== null) {
                    const numeric = Number(payload.duration_seconds);
                    return Number.isFinite(numeric) && numeric > 0 ? numeric : null;
                }
                if (payload.duration_minutes !== undefined && payload.duration_minutes !== null) {
                    const numeric = Number(payload.duration_minutes);
                    return Number.isFinite(numeric) && numeric > 0 ? minutesToSeconds(numeric) : null;
                }
                if (payload.focus_minutes !== undefined && payload.focus_minutes !== null) {
                    return parseMinutesValue(payload.focus_minutes);
                }
                if (payload.break_minutes !== undefined && payload.break_minutes !== null) {
                    return parseMinutesValue(payload.break_minutes);
                }
                if (payload.duration !== undefined && payload.duration !== null) {
                    const numeric = Number(payload.duration);
                    return Number.isFinite(numeric) && numeric > 0 ? numeric : null;
                }
                return null;
            };
            const setPhase = (phase, { autoStart = true, duration = null } = {}) => {
                if (!phases[phase]) return;
                currentPhase = phase;
                const durationSeconds = typeof duration === 'number' && Number.isFinite(duration) && duration > 0 ? duration : defaultDurations[phase];
                phases[phase] = { ...phases[phase], duration: durationSeconds };
                remainingSeconds = durationSeconds;
                updateDisplay();
                if (autoStart) {
                    startCountdown();
                }
            };
            const pauseTimer = () => {
                clearCountdown();
                statusChip.textContent = 'Paused — resume when ready';
                updateDisplay();
            };
            const resumeTimer = () => {
                if (remainingSeconds <= 0) return;
                startCountdown();
            };
            const resetTimer = () => {
                setPhase(currentPhase, { autoStart: false });
                statusChip.textContent = 'Ready for another round';
                updateDisplay();
            };
            const stopTimer = () => {
                clearCountdown();
                remainingSeconds = 0;
                updateDisplay();
                statusChip.textContent = 'Timer stopped';
            };
            window.SpecterWorkingStudyTimer = {
                startPhase: (phaseKey, options) => setPhase(phaseKey, options),
                pause: pauseTimer,
                resume: resumeTimer,
                reset: resetTimer,
                stop: stopTimer
            };
            const timerWrapper = document.getElementById('timerWrapper');
            const timerPlaceholder = document.getElementById('timerPlaceholder');
            const showTimer = urlParams.has('timer');
            if (!showTimer) {
                timerWrapper.style.display = 'none';
                timerPlaceholder.style.display = 'block';
                return;
            }
            timerPlaceholder.style.display = 'none';
            timerWrapper.style.display = 'flex';
            setPhase('focus', { autoStart: false });
            const apiCode = urlParams.get('code');
            if (!apiCode) {
                console.warn('Overlay missing viewer API code; websocket control disabled.');
                return;
            }
            const socketUrl = 'wss://websocket.botofthespecter.com';
            let socket;
            let attempts = 0;
            const parseBool = (value, fallback = false) => {
                if (value === undefined || value === null) return fallback;
                const normalized = String(value).toLowerCase();
                if (['1', 'true', 'yes', 'on'].includes(normalized)) return true;
                if (['0', 'false', 'no', 'off'].includes(normalized)) return false;
                return fallback;
            };
            const scheduleReconnect = () => {
                attempts += 1;
                const delay = Math.min(5000 * attempts, 30000);
                if (socket) {
                    socket.removeAllListeners();
                    socket = null;
                }
                setTimeout(connect, delay);
            };
            const connect = () => {
                socket = io(socketUrl, { reconnection: false });
                socket.on('connect', () => {
                    attempts = 0;
                    socket.emit('REGISTER', { code: apiCode, channel: 'Overlay', name: 'Working Study Timer' });
                });
                socket.on('disconnect', scheduleReconnect);
                socket.on('connect_error', scheduleReconnect);
                socket.on('SPECTER_PHASE', payload => {
                    const phaseKey = (payload.phase || payload.phase_key || '').toLowerCase();
                    if (!phaseKey || !phases[phaseKey]) return;
                    const autoStart = parseBool(payload.auto_start, true);
                    const overriddenDuration = parseDurationOverride(payload);
                    updateDefaultDurationsFromPayload(payload);
                    window.SpecterWorkingStudyTimer.startPhase(phaseKey, { autoStart, duration: overriddenDuration });
                });
                socket.on('SPECTER_TIMER_CONTROL', payload => {
                    const action = (payload.action || payload.command || '').toLowerCase();
                    updateDefaultDurationsFromPayload(payload);
                    if (action === 'pause') {
                        window.SpecterWorkingStudyTimer.pause();
                    } else if (action === 'resume') {
                        window.SpecterWorkingStudyTimer.resume();
                    } else if (action === 'reset') {
                        window.SpecterWorkingStudyTimer.reset();
                    } else if (action === 'start') {
                        window.SpecterWorkingStudyTimer.resume();
                    } else if (action === 'stop') {
                        window.SpecterWorkingStudyTimer.stop();
                    }
                });
                socket.onAny((event, ...args) => {
                    console.debug('Overlay websocket event', event, args);
                });
            };
            connect();
        })();
    </script>
</body>
</html>
