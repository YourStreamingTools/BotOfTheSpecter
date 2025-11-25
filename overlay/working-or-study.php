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
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            min-height: 100vh;
            background: #030409;
            font-family: "Inter", "Segoe UI", system-ui, sans-serif;
            color: #f8fbff;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .timer-card {
            width: min(420px, 90vw);
            padding: 32px;
            border-radius: 28px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.03), rgba(4, 6, 11, 0.95));
            border: 1px solid rgba(255, 255, 255, 0.06);
            box-shadow: 0 30px 65px rgba(0, 0, 0, 0.65);
            display: flex;
            flex-direction: column;
            gap: 20px;
            text-align: center;
        }
        .timer-status {
            font-size: 0.95rem;
            letter-spacing: 0.25em;
            text-transform: uppercase;
            color: var(--accent-color);
        }
        .timer-display {
            font-size: clamp(48px, 8vw, 72px);
            font-weight: 600;
            letter-spacing: 4px;
        }
        .status-chip {
            font-size: 0.95rem;
            padding: 6px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin: 0 auto;
        }
        .controls {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
        }
        .controls button {
            border: none;
            padding: 10px 18px;
            border-radius: 999px;
            font-size: 0.9rem;
            background: rgba(255, 255, 255, 0.09);
            color: inherit;
            cursor: pointer;
            transition: transform 0.2s ease, background 0.2s ease;
        }
        .controls button.active {
            background: var(--accent-color);
            color: #05070a;
            font-weight: 600;
        }
        .controls button:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, 0.15);
        }
    </style>
</head>
<body>
    <div class="timer-card">
        <div class="timer-status" id="phaseLabel">Focus sprint</div>
        <div class="status-chip" id="statusChip">Ready to focus</div>
        <div class="timer-display" id="timerDisplay">00:00</div>
        <div class="controls">
            <button data-phase="focus" class="active">Focus sprint</button>
            <button data-phase="micro">Micro break</button>
            <button data-phase="recharge">Recharge stretch</button>
        </div>
        <div class="controls">
            <button id="pauseToggle">Pause</button>
            <button id="resetTimer">Reset</button>
        </div>
    </div>
    <script>
        (() => {
            const phases = {
                focus: { label: 'Focus sprint', duration: 25 * 60, status: 'Flow mode on', accent: '#ff9161' },
                micro: { label: 'Micro break', duration: 5 * 60, status: 'Recharge quickly', accent: '#6be9ff' },
                recharge: { label: 'Recharge stretch', duration: 15 * 60, status: 'Stretch & hydrate', accent: '#b483ff' }
            };
            let currentPhase = 'focus';
            let remainingSeconds = phases[currentPhase].duration;
            let countdownId = null;
            const phaseLabel = document.getElementById('phaseLabel');
            const statusChip = document.getElementById('statusChip');
            const timerDisplay = document.getElementById('timerDisplay');
            const buttons = document.querySelectorAll('[data-phase]');
            const pauseToggle = document.getElementById('pauseToggle');
            const resetButton = document.getElementById('resetTimer');
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
                buttons.forEach(btn => {
                    btn.classList.toggle('active', btn.dataset.phase === currentPhase);
                });
                pauseToggle.textContent = countdownId ? 'Pause' : 'Resume';
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
            const setPhase = (phase, { autoStart = true } = {}) => {
                if (!phases[phase]) return;
                currentPhase = phase;
                remainingSeconds = phases[phase].duration;
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
            window.SpecterWorkingStudyTimer = {
                startPhase: (phaseKey, options) => setPhase(phaseKey, options),
                pause: pauseTimer,
                resume: resumeTimer,
                reset: resetTimer
            };
            buttons.forEach(btn => {
                btn.addEventListener('click', () => setPhase(btn.dataset.phase));
            });
            pauseToggle.addEventListener('click', () => {
                countdownId ? pauseTimer() : resumeTimer();
            });
            resetButton.addEventListener('click', resetTimer);
            setPhase('focus', { autoStart: true });
            const urlParams = new URLSearchParams(window.location.search);
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
                    window.SpecterWorkingStudyTimer.startPhase(phaseKey, { autoStart });
                });
                socket.on('SPECTER_TIMER_CONTROL', payload => {
                    const action = (payload.action || payload.command || '').toLowerCase();
                    if (action === 'pause') {
                        window.SpecterWorkingStudyTimer.pause();
                    } else if (action === 'resume') {
                        window.SpecterWorkingStudyTimer.resume();
                    } else if (action === 'reset') {
                        window.SpecterWorkingStudyTimer.reset();
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
