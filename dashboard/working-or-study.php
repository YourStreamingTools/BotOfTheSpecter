<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
ini_set('max_execution_time', 300);

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

$pageTitle = 'Working & Study Timer';

$overlayLink = 'https://overlay.botofthespecter.com/working-or-study.php';
$overlayLinkWithCode = $overlayLink . '?code=' . rawurlencode($api_key) . '&timer';

ob_start();
?>
<section class="section">
    <div class="card">
        <header class="card-header">
            <p class="card-header-title">
                Working / Study Overlay Control
            </p>
            <a class="card-header-icon" href="<?php echo htmlspecialchars($overlayLinkWithCode); ?>" target="_blank" rel="noreferrer">
                <span class="icon">
                    <i class="fas fa-external-link-alt" aria-hidden="true"></i>
                </span>
            </a>
        </header>
        <div class="card-content">
            <div class="content">
                <p><strong>Specter Working/Study Timer Overlay</strong></p>
                <p>Control a professional productivity timer overlay displayed on your stream. Track focus sessions, breaks, and recharge time with real-time visual feedback.</p>
                <ul>
                    <li>Visual progress ring that depletes with time</li>
                    <li>Dynamic color coding for each phase: Orange (focus), Cyan (break), Purple (recharge)</li>
                    <li>Session counter and total time logged statistics</li>
                    <li>Sound notifications when phases complete</li>
                    <li>Real-time synchronization via WebSocket</li>
                    <li>Responsive design that scales for stream overlays</li>
                </ul>
            </div>
            <div class="box">
                <div class="buttons" style="margin-bottom: 1rem;">
                    <a class="button is-primary is-loading-toggle" href="<?php echo htmlspecialchars($overlayLinkWithCode); ?>" target="_blank" rel="noreferrer">
                        <span class="icon">
                            <i class="fas fa-external-link-alt" aria-hidden="true"></i>
                        </span>
                        <span>Open Overlay</span>
                    </a>
                </div>
            </div>
            <div class="columns is-multiline">
                <div class="column is-full">
                    <h3 class="title is-6">Duration Settings</h3>
                    <div class="columns">
                        <div class="column is-half">
                            <div class="field">
                                <label class="label">
                                    <span class="icon-text">
                                        <span class="icon">
                                            <i class="fas fa-fire" aria-hidden="true"></i>
                                        </span>
                                        <span>Focus Sprint Duration</span>
                                    </span>
                                </label>
                                <div class="control">
                                    <div class="field has-addons">
                                        <p class="control is-expanded">
                                            <input id="focusLengthMinutes" class="input" type="number" min="1" step="1" value="60" placeholder="Focus minutes">
                                        </p>
                                        <p class="control">
                                            <span class="button is-static">min</span>
                                        </p>
                                    </div>
                                </div>
                                <p class="help">How long to focus before a break</p>
                            </div>
                        </div>
                        <div class="column is-half">
                            <div class="field">
                                <label class="label">
                                    <span class="icon-text">
                                        <span class="icon">
                                            <i class="fas fa-leaf" aria-hidden="true"></i>
                                        </span>
                                        <span>Break Duration</span>
                                    </span>
                                </label>
                                <div class="control">
                                    <div class="field has-addons">
                                        <p class="control is-expanded">
                                            <input id="breakLengthMinutes" class="input" type="number" min="1" step="1" value="15" placeholder="Break minutes">
                                        </p>
                                        <p class="control">
                                            <span class="button is-static">min</span>
                                        </p>
                                    </div>
                                </div>
                                <p class="help">Applies to both micro and recharge breaks</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column is-half">
                    <h3 class="title is-6">
                        <span class="icon-text">
                            <span class="icon">
                                <i class="fas fa-bolt" aria-hidden="true"></i>
                            </span>
                            <span>Phase Controls</span>
                        </span>
                    </h3>
                    <div class="buttons is-flex-wrap-wrap">
                        <button type="button" class="button is-medium is-danger" data-specter-phase="focus" style="flex: 1; min-width: 100%; margin-bottom: 0.5rem;">
                            <span class="icon">
                                <i class="fas fa-hourglass-start" aria-hidden="true"></i>
                            </span>
                            <span>Start Focus Sprint</span>
                        </button>
                        <button type="button" class="button is-medium is-info" data-specter-phase="micro" style="flex: 1; min-width: 100%; margin-bottom: 0.5rem;">
                            <span class="icon">
                                <i class="fas fa-mug-hot" aria-hidden="true"></i>
                            </span>
                            <span>Start Micro Break</span>
                        </button>
                        <button type="button" class="button is-medium is-warning" data-specter-phase="recharge" style="flex: 1; min-width: 100%; margin-bottom: 0;">
                            <span class="icon">
                                <i class="fas fa-stretch" aria-hidden="true"></i>
                            </span>
                            <span>Start Recharge Stretch</span>
                        </button>
                    </div>
                </div>
                <div class="column is-half">
                    <h3 class="title is-6">
                        <span class="icon-text">
                            <span class="icon">
                                <i class="fas fa-play" aria-hidden="true"></i>
                            </span>
                            <span>Timer Controls</span>
                        </span>
                    </h3>
                    <div class="buttons is-flex-wrap-wrap">
                        <button type="button" class="button is-medium is-primary" data-specter-control="start" style="flex: 1; min-width: calc(50% - 0.25rem); margin-right: 0.5rem; margin-bottom: 0.5rem;">
                            <span class="icon">
                                <i class="fas fa-play" aria-hidden="true"></i>
                            </span>
                            <span>Start</span>
                        </button>
                        <button type="button" class="button is-medium is-warning" data-specter-control="pause" style="flex: 1; min-width: calc(50% - 0.25rem); margin-bottom: 0.5rem;">
                            <span class="icon">
                                <i class="fas fa-pause" aria-hidden="true"></i>
                            </span>
                            <span>Pause</span>
                        </button>
                        <button type="button" class="button is-medium is-success" data-specter-control="resume" style="flex: 1; min-width: calc(50% - 0.25rem); margin-right: 0.5rem; margin-bottom: 0.5rem;">
                            <span class="icon">
                                <i class="fas fa-redo" aria-hidden="true"></i>
                            </span>
                            <span>Resume</span>
                        </button>
                        <button type="button" class="button is-medium is-info" data-specter-control="reset" style="flex: 1; min-width: calc(50% - 0.25rem); margin-bottom: 0.5rem;">
                            <span class="icon">
                                <i class="fas fa-sync" aria-hidden="true"></i>
                            </span>
                            <span>Reset</span>
                        </button>
                        <button type="button" class="button is-medium is-danger" data-specter-control="stop" style="flex: 1; min-width: 100%;">
                            <span class="icon">
                                <i class="fas fa-stop" aria-hidden="true"></i>
                            </span>
                            <span>Stop</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="toast-area" id="toastArea" aria-live="polite" role="status"></div>
</section>
<?php
$content = ob_get_clean();

ob_start();
?>
<script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
<script>
    (function () {
        const apiKey = <?php echo json_encode($api_key); ?>;
        const buttonsPhase = document.querySelectorAll('[data-specter-phase]');
        const buttonsControl = document.querySelectorAll('[data-specter-control]');
        const focusLengthInput = document.getElementById('focusLengthMinutes');
        const breakLengthInput = document.getElementById('breakLengthMinutes');
        const toastArea = document.getElementById('toastArea');
        const startBtn = document.querySelector('[data-specter-control="start"]');
        const pauseBtn = document.querySelector('[data-specter-control="pause"]');
        const resumeBtn = document.querySelector('[data-specter-control="resume"]');
        const stopBtn = document.querySelector('[data-specter-control="stop"]');
        const resetBtn = document.querySelector('[data-specter-control="reset"]');
        let isRequesting = false;
        let timerState = 'stopped'; // stopped, running, paused
        let sessionsCompleted = 0;
        let totalTimeLogged = 0;
        const getToastArea = () => {
            if (toastArea) return toastArea;
            const fallback = document.querySelector('.toast-area');
            if (fallback) return fallback;
            const created = document.createElement('div');
            created.className = 'toast-area';
            document.body.appendChild(created);
            return created;
        };
        const showToast = (message, type = 'success') => {
            if (!message) return;
            const area = getToastArea();
            const toast = document.createElement('div');
            toast.className = `working-study-toast ${type}`;
            toast.innerHTML = `<div style="display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-${type === 'danger' ? 'exclamation-circle' : 'check-circle'}" style="font-size: 16px;"></i>
                <span>${message}</span>
            </div>`;
            area.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('visible'));
            setTimeout(() => {
                toast.classList.remove('visible');
                toast.addEventListener('transitionend', () => toast.remove(), { once: true });
            }, 3500);
        };
        const updateButtonStates = () => {
            if (timerState === 'stopped') {
                startBtn.style.display = 'block';
                pauseBtn.style.display = 'none';
                resumeBtn.style.display = 'none';
                stopBtn.style.opacity = '0.5';
                stopBtn.style.cursor = 'not-allowed';
                stopBtn.disabled = true;
            } else if (timerState === 'running') {
                startBtn.style.display = 'none';
                pauseBtn.style.display = 'block';
                resumeBtn.style.display = 'none';
                stopBtn.style.opacity = '1';
                stopBtn.style.cursor = 'pointer';
                stopBtn.disabled = false;
            } else if (timerState === 'paused') {
                startBtn.style.display = 'none';
                pauseBtn.style.display = 'none';
                resumeBtn.style.display = 'block';
                stopBtn.style.opacity = '1';
                stopBtn.style.cursor = 'pointer';
                stopBtn.disabled = false;
            }
        };
        
        const formatTotalTime = (totalSeconds) => {
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            if (hours > 0) {
                return `${hours}h ${minutes}m`;
            }
            return `${minutes}m`;
        };
        
        const updateStatsDisplay = () => {
            console.log(`[Timer Dashboard] Session stats updated - Sessions: ${sessionsCompleted}, Total Time: ${formatTotalTime(totalTimeLogged)}`);
        };
        const phaseNames = {
            focus: 'Focus Sprint',
            micro: 'Micro Break',
            recharge: 'Recharge Stretch'
        };
        const controlMessages = {
            start: 'Timer started',
            pause: 'Timer paused',
            resume: 'Timer resumed',
            reset: 'Timer reset',
            stop: 'Timer stopped'
        };
        const setButtonsLoading = (loading) => {
            isRequesting = loading;
            const allButtons = document.querySelectorAll('[data-specter-phase], [data-specter-control]');
            allButtons.forEach(btn => {
                if (btn.style.display !== 'none') {
                    btn.disabled = loading;
                    btn.style.opacity = loading ? '0.6' : btn.style.opacity;
                    btn.style.cursor = loading ? 'not-allowed' : btn.style.cursor;
                }
            });
        };
        const notifyServer = async (payload, toastMessage = '', toastType = 'success') => {
            if (isRequesting) return;
            setButtonsLoading(true);
            try {
                if (!socket || !socket.connected) {
                    console.error('[Timer Dashboard] Socket not connected');
                    showToast('⚠️ Not connected to timer server', 'danger');
                    setButtonsLoading(false);
                    return;
                }
                console.log('[Timer Dashboard] Sending command via WebSocket:', payload);
                // Send via WebSocket instead of HTTP
                socket.emit(payload.specter_event, {
                    code: apiKey,
                    ...payload
                });
                if (toastMessage) {
                    console.log(`[Timer Dashboard] Command sent: ${toastMessage}`);
                    showToast(`✓ ${toastMessage}`, toastType);
                }
            } catch (error) {
                console.warn('Dashboard notify error', error);
                showToast('⚠️ Error communicating with timer', 'danger');
            } finally {
                setButtonsLoading(false);
            }
        };
        const safeNumberValue = (input, fallback) => {
            if (!input) return fallback;
            const numeric = Number(input.value);
            return Number.isFinite(numeric) && numeric > 0 ? numeric : fallback;
        };
        const gatherDurations = () => ({
            duration_minutes: safeNumberValue(focusLengthInput, 60),
            focus_minutes: safeNumberValue(focusLengthInput, 60),
            break_minutes: safeNumberValue(breakLengthInput, 15)
        });
        // WebSocket connection for real-time sync
        const socketUrl = 'wss://websocket.botofthespecter.com';
        let socket;
        let attempts = 0;
        const scheduleReconnect = () => {
            attempts += 1;
            const delay = Math.min(5000 * attempts, 30000);
            console.log(`[Timer Dashboard] Reconnect scheduled in ${delay}ms (attempt ${attempts})`);
            if (socket) {
                socket.removeAllListeners();
                socket = null;
            }
            setTimeout(connect, delay);
        };
        const connect = () => {
            console.log(`[Timer Dashboard] Connecting to WebSocket: ${socketUrl}`);
            socket = io(socketUrl, { reconnection: false });
            socket.on('connect', () => {
                attempts = 0;
                console.log('[Timer Dashboard] ✓ Connected to WebSocket');
                console.log('[Timer Dashboard] Registering as Dashboard');
                socket.emit('REGISTER', { code: apiKey, channel: 'Dashboard', name: 'Working Study Timer Dashboard' });
            });
            socket.on('disconnect', (reason) => {
                console.warn(`[Timer Dashboard] ✗ Disconnected: ${reason}`);
                scheduleReconnect();
            });
            socket.on('connect_error', (error) => {
                console.error('[Timer Dashboard] Connection error:', error);
                scheduleReconnect();
            });
            socket.on('SPECTER_TIMER_STATE', payload => {
                console.log('[Timer Dashboard] Received timer state update:', payload);
                const newState = (payload.state || '').toLowerCase();
                if (['stopped', 'running', 'paused'].includes(newState)) {
                    console.log(`[Timer Dashboard] Timer state changed to: ${newState}`);
                    timerState = newState;
                    updateButtonStates();
                }
            });
            socket.on('SPECTER_SESSION_STATS', payload => {
                console.log('[Timer Dashboard] Received session stats update:', payload);
                sessionsCompleted = payload.sessionsCompleted || 0;
                totalTimeLogged = payload.totalTimeLogged || 0;
                updateStatsDisplay();
            });
            socket.onAny((event, ...args) => {
                console.debug(`[Timer Dashboard] WebSocket event: ${event}`, args);
            });
        };
        connect();
        // Initialize button states
        updateButtonStates();
        buttonsPhase.forEach(button => {
            button.addEventListener('click', async () => {
                const phase = button.getAttribute('data-specter-phase');
                const payload = { phase, auto_start: 1 };
                if (phase === 'focus') {
                    payload.duration_minutes = safeNumberValue(focusLengthInput, 60);
                } else if (phase === 'micro' || phase === 'recharge') {
                    payload.duration_minutes = safeNumberValue(breakLengthInput, 15);
                }
                const phaseName = phaseNames[phase] || phase;
                await notifyServer(
                    { specter_event: 'SPECTER_PHASE', ...payload, ...gatherDurations() }, 
                    `Started ${phaseName}`, 
                    'success'
                );
            });
        });
        buttonsControl.forEach(button => {
            button.addEventListener('click', async () => {
                const action = button.getAttribute('data-specter-control');
                const toastMessage = controlMessages[action] || `Timer ${action}`;
                await notifyServer(
                    { specter_event: 'SPECTER_TIMER_CONTROL', action, ...gatherDurations() }, 
                    toastMessage
                );
            });
        });
        // Input validation
        focusLengthInput.addEventListener('change', () => {
            const val = Number(focusLengthInput.value);
            if (!Number.isFinite(val) || val < 1) {
                focusLengthInput.value = 60;
                showToast('⚠️ Focus duration must be at least 1 minute', 'danger');
            }
        });
        breakLengthInput.addEventListener('change', () => {
            const val = Number(breakLengthInput.value);
            if (!Number.isFinite(val) || val < 1) {
                breakLengthInput.value = 15;
                showToast('⚠️ Break duration must be at least 1 minute', 'danger');
            }
        });
    })();
</script>
<?php
$scripts = ob_get_clean();

include 'layout.php';
?>