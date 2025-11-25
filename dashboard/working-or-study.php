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
    $allowedFields = ['phase', 'auto_start', 'action', 'duration_minutes', 'duration_seconds'];
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
                <p>The Specter working/study overlay keeps a single timer synced with the familiar Specter cadence so your chat can follow along. Open the overlay in another tab or capture it in your stream layout, and it will respond to the same controls below.</p>
                <ul>
                    <li>Three ready-made phases: focus sprint, micro break, and recharge stretch.</li>
                    <li>The timer card shows current status, accent color, and countdown without extra clutter.</li>
                    <li>Use the phase buttons to jump between blocks, then pause or reset with the timer controls.</li>
                </ul>
            </div>
            <div class="columns is-multiline">
                <div class="column is-half">
                    <h3 class="title is-6">Phase controls</h3>
                    <div class="buttons is-flex-wrap-wrap">
                        <button type="button" class="button is-primary" data-specter-phase="focus">Start focus sprint</button>
                        <button type="button" class="button is-link" data-specter-phase="micro">Start micro break</button>
                        <button type="button" class="button is-warning" data-specter-phase="recharge">Start recharge stretch</button>
                    </div>
                </div>
                <div class="column is-half">
                    <h3 class="title is-6">Timer controls</h3>
                    <div class="buttons">
                        <button type="button" class="button is-primary" data-specter-control="start">Start</button>
                        <button type="button" class="button is-info" data-specter-control="pause">Pause</button>
                        <button type="button" class="button is-success" data-specter-control="resume">Resume</button>
                        <button type="button" class="button is-danger" data-specter-control="reset">Reset</button>
                    </div>
                </div>
            </div>
            <div class="columns">
                <div class="column is-half">
                    <div class="field">
                        <label class="label is-size-7">Focus length (minutes)</label>
                        <div class="control">
                            <input id="focusLengthMinutes" class="input" type="number" min="1" step="1" value="60" placeholder="Focus minutes">
                        </div>
                    </div>
                </div>
                <div class="column is-half">
                    <div class="field">
                        <label class="label is-size-7">Break length (minutes)</label>
                        <div class="control">
                            <input id="breakLengthMinutes" class="input" type="number" min="1" step="1" value="15" placeholder="Break minutes">
                        </div>
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
            toast.textContent = message;
            area.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('visible'));
            setTimeout(() => {
                toast.classList.remove('visible');
                toast.addEventListener('transitionend', () => toast.remove(), { once: true });
            }, 3200);
        };
        const phaseNames = {
            focus: 'Focus sprint',
            micro: 'Micro break',
            recharge: 'Recharge stretch'
        };
        const controlMessages = {
            start: 'Timer started',
            pause: 'Timer paused',
            resume: 'Timer resumed',
            reset: 'Timer reset'
        };
        const notifyServer = async (payload, toastMessage = '', toastType = 'success') => {
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
                    showToast('Timer request failed', 'danger');
                    return;
                }
                const json = await response.json();
                if (toastMessage && json.status === 'ok') {
                    showToast(toastMessage, toastType);
                }
                return json;
            } catch (error) {
                console.warn('Dashboard notify request error', error);
                showToast('Timer request failed', 'danger');
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
            button.addEventListener('click', () => {
                const phase = button.getAttribute('data-specter-phase');
                const payload = { phase, auto_start: 1 };
                if (phase === 'focus') {
                    payload.duration_minutes = safeNumberValue(focusLengthInput, 60);
                } else if (phase === 'micro' || phase === 'recharge') {
                    payload.duration_minutes = safeNumberValue(breakLengthInput, 15);
                }
                const phaseName = phaseNames[phase] || phase;
                notifyServer({ specter_event: 'SPECTER_PHASE', ...payload, ...gatherDurations() }, `Timer started ${phaseName}`);
            });
        });
        buttonsControl.forEach(button => {
            button.addEventListener('click', () => {
                const action = button.getAttribute('data-specter-control');
                const toastMessage = controlMessages[action] || `Timer ${action}`;
                notifyServer({ specter_event: 'SPECTER_TIMER_CONTROL', action, ...gatherDurations() }, toastMessage);
            });
        });
    })();
</script>
<?php
$scripts = ob_get_clean();

include 'layout.php';
?>