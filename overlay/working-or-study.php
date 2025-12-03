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
            --focus-color: #ff9161;
            --break-color: #6be9ff;
            --recharge-color: #b483ff;
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
            padding: 20px;
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
            width: 100%;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .timer-card {
            width: var(--timer-width);
            padding: 40px;
            border-radius: 32px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.05), rgba(4, 6, 11, 0.98));
            border: 2px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 50px 100px rgba(0, 0, 0, 0.75), 
                        inset 0 1px rgba(255, 255, 255, 0.1);
            display: flex;
            flex-direction: column;
            gap: 24px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .timer-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-color), transparent);
            opacity: 0.6;
        }
        .timer-ring-container {
            position: relative;
            width: 280px;
            height: 280px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .timer-ring {
            position: relative;
            width: 100%;
            height: 100%;
            transform: rotate(-90deg);
        }
        .timer-ring svg {
            width: 100%;
            height: 100%;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.3));
        }
        .timer-ring-progress {
            stroke: var(--accent-color);
            stroke-linecap: round;
            transition: stroke-dashoffset 1s linear, stroke 0.3s ease;
            filter: drop-shadow(0 2px 4px rgba(255, 255, 255, 0.2));
        }
        .timer-ring-background {
            stroke: rgba(255, 255, 255, 0.08);
            stroke-linecap: round;
        }
        .timer-display-inner {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }
        .timer-display {
            font-size: clamp(48px, 8vw, 72px);
            font-weight: 700;
            letter-spacing: 2px;
            font-variant-numeric: tabular-nums;
            text-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
        }
        .timer-milliseconds {
            font-size: calc(0.4em);
            opacity: 0.7;
            margin-top: calc(-4px / var(--overlay-scale));
        }
        .timer-status {
            font-size: 0.9rem;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: var(--accent-color);
            font-weight: 600;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
            animation: phaseGlow 2s ease-in-out infinite;
        }
        @keyframes phaseGlow {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        .status-chip {
            font-size: 0.85rem;
            padding: 8px 16px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0 auto;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }
        .status-chip.active {
            background: rgba(255, 255, 255, 0.12);
            border-color: var(--accent-color);
            box-shadow: 0 0 16px rgba(255, 255, 255, 0.15);
        }
        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--accent-color);
            animation: pulse 2s ease-in-out infinite;
            box-shadow: 0 0 8px var(--accent-color);
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .session-stats {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.6);
            display: flex;
            justify-content: space-around;
            padding-top: 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            gap: 8px;
        }
        .stat-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }
        .stat-value {
            color: var(--accent-color);
            font-weight: 600;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="placeholder" id="timerPlaceholder">Append <code>&timer</code> to the overlay URL to show the Specter timer.</div>
    <div class="timer-wrapper" id="timerWrapper">
        <div class="timer-card" id="timerCard">
            <div class="timer-status" id="phaseLabel">Focus sprint</div>
            <div class="timer-ring-container">
                <div class="timer-ring">
                    <svg viewBox="0 0 280 280">
                        <circle class="timer-ring-background" cx="140" cy="140" r="130" fill="none" stroke-width="12"/>
                        <circle id="timerRingProgress" class="timer-ring-progress" cx="140" cy="140" r="130" fill="none" stroke-width="12"/>
                    </svg>
                    <div class="timer-display-inner">
                        <div class="timer-display" id="timerDisplay">00:00</div>
                    </div>
                </div>
            </div>
            <div class="status-chip" id="statusChip">
                <span class="status-indicator"></span>
                <span id="statusText">Ready to focus</span>
            </div>
            <div class="session-stats" id="sessionStats">
                <div class="stat-item">
                    <span id="sessionsCompleted">0</span>
                    <span>Sessions</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value" id="totalTimeLogged">0m</span>
                    <span>Total Time</span>
                </div>
            </div>
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
            let totalDurationForPhase = phases[currentPhase].duration;
            let countdownId = null;
            let sessionsCompleted = 0;
            let totalTimeLogged = 0;
            const phaseLabel = document.getElementById('phaseLabel');
            const statusChip = document.getElementById('statusChip');
            const statusText = document.getElementById('statusText');
            const timerDisplay = document.getElementById('timerDisplay');
            const timerRingProgress = document.getElementById('timerRingProgress');
            const sessionsCompletedEl = document.getElementById('sessionsCompleted');
            const totalTimeLoggedEl = document.getElementById('totalTimeLogged');
            const circumference = 2 * Math.PI * 130;
            const formatTime = seconds => {
                const mins = Math.floor(seconds / 60);
                const secs = seconds % 60;
                return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
            };
            const formatTotalTime = totalSeconds => {
                const hours = Math.floor(totalSeconds / 3600);
                const minutes = Math.floor((totalSeconds % 3600) / 60);
                if (hours > 0) {
                    return `${hours}h ${minutes}m`;
                }
                return `${minutes}m`;
            };
            const updateProgressRing = () => {
                const progressPercent = remainingSeconds / totalDurationForPhase;
                const offset = circumference * (1 - progressPercent);
                timerRingProgress.style.strokeDasharray = circumference;
                timerRingProgress.style.strokeDashoffset = offset;
            };
            const updateDisplay = () => {
                phaseLabel.textContent = phases[currentPhase].label;
                statusText.textContent = phases[currentPhase].status;
                timerDisplay.textContent = formatTime(remainingSeconds);
                document.documentElement.style.setProperty('--accent-color', phases[currentPhase].accent);
                timerRingProgress.style.stroke = phases[currentPhase].accent;
                updateProgressRing();
            };
            const clearCountdown = () => {
                if (countdownId) {
                    clearInterval(countdownId);
                    countdownId = null;
                }
            };
            const playNotificationSound = () => {
                try {
                    const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                    const now = audioContext.currentTime;
                    const osc = audioContext.createOscillator();
                    const env = audioContext.createGain();
                    osc.connect(env);
                    env.connect(audioContext.destination);
                    osc.frequency.value = 800;
                    env.gain.setValueAtTime(0.3, now);
                    env.gain.exponentialRampToValueAtTime(0.01, now + 0.3);
                    osc.start(now);
                    osc.stop(now + 0.3);
                } catch (e) {
                    console.debug('Audio notification not available');
                }
            };
            const startCountdown = () => {
                clearCountdown();
                statusChip.classList.add('active');
                countdownId = setInterval(() => {
                    if (remainingSeconds <= 0) {
                        clearCountdown();
                        statusChip.classList.remove('active');
                        statusText.textContent = 'Session complete — choose next phase';
                        playNotificationSound();
                        sessionsCompleted += 1;
                        totalTimeLogged += totalDurationForPhase;
                        updateStats();
                        return;
                    }
                    remainingSeconds -= 1;
                    updateDisplay();
                }, 1000);
                updateDisplay();
            };
            const pauseTimer = () => {
                clearCountdown();
                statusChip.classList.remove('active');
                statusText.textContent = 'Paused — resume when ready';
                updateDisplay();
            };
            const resumeTimer = () => {
                if (remainingSeconds <= 0) return;
                statusChip.classList.add('active');
                startCountdown();
            };
            const resetTimer = () => {
                clearCountdown();
                statusChip.classList.remove('active');
                remainingSeconds = defaultDurations[currentPhase];
                totalDurationForPhase = defaultDurations[currentPhase];
                statusText.textContent = 'Ready for another round';
                updateDisplay();
            };
            const stopTimer = () => {
                clearCountdown();
                statusChip.classList.remove('active');
                remainingSeconds = 0;
                updateDisplay();
                statusText.textContent = 'Timer stopped';
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
            const updateStats = () => {
                sessionsCompletedEl.textContent = sessionsCompleted;
                totalTimeLoggedEl.textContent = formatTotalTime(totalTimeLogged);
            };
            const setPhase = (phase, { autoStart = true, duration = null } = {}) => {
                if (!phases[phase]) return;
                currentPhase = phase;
                const durationSeconds = typeof duration === 'number' && Number.isFinite(duration) && duration > 0 ? duration : defaultDurations[phase];
                phases[phase] = { ...phases[phase], duration: durationSeconds };
                remainingSeconds = durationSeconds;
                totalDurationForPhase = durationSeconds;
                updateDisplay();
                if (autoStart) {
                    startCountdown();
                }
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
            updateStats();
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
                    const overriddenDuration = parseDurationOverride(payload);
                    if (action === 'pause') {
                        window.SpecterWorkingStudyTimer.pause();
                    } else if (action === 'resume') {
                        window.SpecterWorkingStudyTimer.resume();
                    } else if (action === 'reset') {
                        window.SpecterWorkingStudyTimer.reset();
                    } else if (action === 'start') {
                        if (typeof overriddenDuration === 'number') {
                            window.SpecterWorkingStudyTimer.startPhase(currentPhase, { autoStart: true, duration: overriddenDuration });
                        } else {
                            window.SpecterWorkingStudyTimer.resume();
                        }
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