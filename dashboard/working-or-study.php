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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['specter_event'])) {
    $event = $_POST['specter_event'];
    $allowedFields = ['phase', 'auto_start', 'action', 'duration_minutes', 'duration_seconds', 'focus_minutes', 'break_minutes'];
    $params = ['code' => $api_key, 'event' => $event];
    foreach ($allowedFields as $field) {
        if (!empty($_POST[$field]) || $_POST[$field] === '0') {
            $params[$field] = $_POST[$field];
        }
    }
    $notifyUrl = 'https://websocket.botofthespecter.com/notify?' . http_build_query($params);
    $context = stream_context_create(['http' => ['timeout' => 5]]);
    $response = @file_get_contents($notifyUrl, false, $context);
    header('Content-Type: application/json');
    echo json_encode([
        'event' => $event,
        'status' => $response === false ? 'error' : 'ok',
        'response' => $response ?: null,
        'params' => $params,
    ]);
    exit;
}

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
<script>
    (function () {
        const apiKey = <?php echo json_encode($api_key); ?>;
        const buttonsPhase = document.querySelectorAll('[data-specter-phase]');
        const buttonsControl = document.querySelectorAll('[data-specter-control]');
        const focusLengthInput = document.getElementById('focusLengthMinutes');
        const breakLengthInput = document.getElementById('breakLengthMinutes');
        const toastArea = document.getElementById('toastArea');
        
        let isRequesting = false;
        
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
                btn.disabled = loading;
                btn.style.opacity = loading ? '0.6' : '1';
                btn.style.cursor = loading ? 'not-allowed' : 'pointer';
            });
        };
        
        const notifyServer = async (payload, toastMessage = '', toastType = 'success') => {
            if (isRequesting) return;
            setButtonsLoading(true);
            const body = new URLSearchParams(payload);
            try {
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body,
                    cache: 'no-cache'
                });
                if (!response.ok) {
                    console.warn('Dashboard notify request failed', response.status, response.statusText);
                    showToast('⚠️ Timer request failed', 'danger');
                    setButtonsLoading(false);
                    return;
                }
                const json = await response.json();
                if (json.status === 'ok') {
                    if (toastMessage) {
                        showToast(`✓ ${toastMessage}`, toastType);
                    }
                } else {
                    showToast('⚠️ Failed to send timer command', 'danger');
                }
                return json;
            } catch (error) {
                console.warn('Dashboard notify request error', error);
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