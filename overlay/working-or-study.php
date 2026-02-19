<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Handle GET requests for tasks
ob_start();

$error_html = null;
$user_id = null;
$username = null;
$conn = null;
$user_db = null;
$has_timer_query = array_key_exists('timer', $_GET);
$has_tasklist_query = array_key_exists('tasklist', $_GET);
$show_timer_panel = true;
$overlay_mode_class = '';
if ($has_timer_query && !$has_tasklist_query) {
    $overlay_mode_class = 'study-overlay--timer-only';
} elseif ($has_tasklist_query && !$has_timer_query) {
    $show_timer_panel = false;
    $overlay_mode_class = 'study-overlay--tasks-only';
}
// New task system panel visibility
// ?tasklist                 -> both streamer + viewer task panels visible
// ?tasklist&streamer=true   -> only streamer task panel visible
// ?tasklist&streamer=false  -> only viewer task panel visible
$streamer_filter_param = isset($_GET['streamer']) ? $_GET['streamer'] : null;
$show_new_streamer_panel = $has_tasklist_query && ($streamer_filter_param !== 'false');
$show_new_viewer_panel   = $has_tasklist_query && ($streamer_filter_param !== 'true');

include '/var/www/config/database.php';

// Connect to primary database
$primary_db_name = "website";
$conn = new mysqli($db_servername, $db_username, $db_password, $primary_db_name);
if ($conn->connect_error) {
    ob_end_clean();
    die("Connection to primary database failed: " . $conn->connect_error);
}

// Validate API key and get user info
if (isset($_GET['code']) && !empty($_GET['code'])) {
    $api_key = $_GET['code'];
    $stmt = $conn->prepare("SELECT id, username FROM users WHERE api_key = ?");
    $stmt->bind_param("s", $api_key);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    if ($user) {
        $user_id = $user['id'];
        $username = $user['username'];
    } else {
        $error_html = "Invalid API key.<br>Get your API Key from your <a href='https://dashboard.botofthespecter.com/profile.php'>profile</a>."
            . "<p>If you wish to display a task list, please add it like this: <strong>working-or-study.php?code=API_KEY&tasklist</strong></br>"
            . "(where tasklist triggers the task list overlay mode.)</p>";
    }
} else {
    $error_html = "<p>Please provide your API key in the URL like this: <strong>working-or-study.php?code=API_KEY</strong></p>"
        . "<p>Get your API Key from your <a href='https://dashboard.botofthespecter.com/profile.php'>profile</a>.</p>"
        . "<p>If you wish to display a task list, please add it like this: <strong>working-or-study.php?code=API_KEY&tasklist</strong></p>";
}

// Connect to secondary (user) database
if (!$error_html) {
    $secondary_db_name = $username;
    $user_db = new mysqli($db_servername, $db_username, $db_password, $secondary_db_name);
    if ($user_db->connect_error) {
        $error_html = "Connection to user database failed: " . htmlspecialchars($user_db->connect_error);
    } else {
        $tables_to_create = [
            'working_study_overlay_settings' => "
                CREATE TABLE IF NOT EXISTS working_study_overlay_settings (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    focus_minutes INT DEFAULT 60,
                    micro_break_minutes INT DEFAULT 5,
                    recharge_break_minutes INT DEFAULT 30,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];
        foreach ($tables_to_create as $table_name => $create_sql) {
            $table_exists = $user_db->query("SHOW TABLES LIKE '$table_name'")->num_rows > 0;
            if (!$table_exists) {
                if (!$user_db->query($create_sql)) {
                    error_log("Failed to create table $table_name: " . $user_db->error);
                }
            }
        }
    }
}

// Handle API requests for getting tasks
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    $action = $_GET['action'];
    if ($error_html) {
        echo json_encode(['success' => false, 'error' => strip_tags($error_html)]);
        exit;
    }
    if ($action === 'get_settings') {
        $stmt = $user_db->prepare("SELECT focus_minutes, micro_break_minutes, recharge_break_minutes FROM working_study_overlay_settings LIMIT 1");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $user_db->error]);
            exit;
        }
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => 'Execute failed: ' . $stmt->error]);
            exit;
        }
        $result = $stmt->get_result();
        $settings = $result->fetch_assoc();
        $stmt->close();
        if ($settings) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'focus_minutes' => (int) $settings['focus_minutes'],
                    'micro_break_minutes' => (int) $settings['micro_break_minutes'],
                    'recharge_break_minutes' => (int) $settings['recharge_break_minutes']
                ]
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'data' => [
                    'focus_minutes' => 60,
                    'micro_break_minutes' => 5,
                    'recharge_break_minutes' => 30
                ]
            ]);
        }
        exit;
    }
    if ($action === 'get_channel_tasks') {
        // Returns streamer_tasks and user_tasks from the new channel task system
        $streamer_tasks_arr = [];
        $user_tasks_arr = [];
        // Check if the tables exist before querying
        $st_exists = $user_db->query("SHOW TABLES LIKE 'streamer_tasks'")->num_rows > 0;
        $ut_exists = $user_db->query("SHOW TABLES LIKE 'user_tasks'")->num_rows > 0;
        if ($st_exists) {
            $s = $user_db->prepare("SELECT id, title, description, category, status, reward_points FROM streamer_tasks WHERE status != 'hidden' ORDER BY created_at DESC");
            if ($s && $s->execute()) {
                $streamer_tasks_arr = $s->get_result()->fetch_all(MYSQLI_ASSOC);
                $s->close();
            }
        }
        if ($ut_exists) {
            $u = $user_db->prepare("SELECT id, user_name, title, description, status, reward_points, completed_at FROM user_tasks WHERE status != 'rejected' ORDER BY created_at DESC");
            if ($u && $u->execute()) {
                $user_tasks_arr = $u->get_result()->fetch_all(MYSQLI_ASSOC);
                $u->close();
            }
        }
        echo json_encode(['success' => true, 'streamer_tasks' => $streamer_tasks_arr, 'user_tasks' => $user_tasks_arr]);
        exit;
    }
}

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Specter Working/Study Timer</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
    <link rel="stylesheet" href="index.css">
    <style>
        /* ── New task system panels ─────────────────────────────────── */
        .task-sys-card {
            background: rgba(20, 20, 30, 0.85);
            border: 1px solid rgba(255,255,255,.13);
            border-radius: 10px;
            min-width: 200px;
            max-width: 340px;
            overflow: hidden;
            flex-shrink: 0;
        }
        .task-sys-card__header {
            padding: 8px 12px;
            background: rgba(255,255,255,.07);
            font-weight: 700;
            font-size: .78rem;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .task-sys-card__dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .task-sys-card__list {
            list-style: none;
            margin: 0;
            padding: 6px 0;
            max-height: 420px;
            overflow-y: auto;
        }
        .task-sys-card__list:empty::after {
            content: 'No tasks';
            display: block;
            text-align: center;
            padding: 14px;
            color: rgba(255,255,255,.38);
            font-style: italic;
            font-size: .75rem;
        }
        .task-sys-item {
            padding: 5px 12px;
            display: flex;
            align-items: flex-start;
            gap: 9px;
            border-bottom: 1px solid rgba(255,255,255,.05);
            animation: taskSlideIn .3s ease;
        }
        .task-sys-item:last-child { border-bottom: none; }
        .task-sys-item.is-done { opacity: .4; }
        .task-sys-item__check {
            margin-top: 3px;
            width: 14px; height: 14px;
            border-radius: 50%;
            border: 2px solid #48c78e;
            flex-shrink: 0;
        }
        .task-sys-item.is-done .task-sys-item__check {
            background: #48c78e;
            position: relative;
        }
        .task-sys-item.is-done .task-sys-item__check::after {
            content: '\2713';
            position: absolute;
            top: -2px; left: 1px;
            font-size: .5rem;
            color: #fff;
        }
        .task-sys-item__body { flex: 1; min-width: 0; }
        .task-sys-item__title {
            font-size: .78rem;
            font-weight: 600;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .task-sys-item.is-done .task-sys-item__title { text-decoration: line-through; }
        .task-sys-item__meta {
            font-size: .65rem;
            color: rgba(255,255,255,.45);
            margin-top: 1px;
        }
        .task-sys-item__pts {
            font-size: .68rem;
            color: #ffd700;
            font-weight: 700;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .task-sys-reward {
            position: fixed;
            bottom: 18px; right: 18px;
            background: #23d160;
            color: #fff;
            border-radius: 8px;
            padding: 9px 15px;
            font-weight: 700;
            font-size: .85rem;
            box-shadow: 0 4px 16px rgba(0,0,0,.45);
            animation: taskPopIn .4s ease;
            z-index: 1000;
        }
        @keyframes taskSlideIn {
            from { opacity: 0; transform: translateX(-10px); }
            to   { opacity: 1; transform: none; }
        }
        @keyframes taskPopIn {
            0%   { transform: scale(.6); opacity: 0; }
            70%  { transform: scale(1.07); }
            100% { transform: scale(1); opacity: 1; }
        }
    </style>
</head>
<body class="study-overlay-page">
    <?php if ($error_html): ?>
        <div class="study-overlay-error-screen">
            <h1>Overlay unavailable</h1>
            <p id="overlayErrorMessage"><?php echo $error_html; ?></p>
        </div>
    <?php else: ?>
        <div class="study-overlay-root<?php echo $overlay_mode_class ? ' ' . htmlspecialchars($overlay_mode_class) : ''; ?>"
            id="overlayRoot">
            <section class="study-overlay-timer-card" data-visible="<?php echo $show_timer_panel ? 'true' : 'false'; ?>">
                <div class="study-overlay-timer-ring">
                    <svg viewBox="0 0 220 220">
                        <circle class="study-overlay-ring-bg" cx="110" cy="110" r="98"></circle>
                        <circle class="study-overlay-ring-progress" id="timerRingProgress" cx="110" cy="110" r="98">
                        </circle>
                    </svg>
                    <div class="study-overlay-timer-inner">
                        <div id="phaseLabel" class="study-overlay-phase-label">Focus Sprint</div>
                        <div id="timerDisplay" class="study-overlay-timer-display">00:00</div>
                        <div id="statusText" class="study-overlay-timer-status">Waiting</div>
                    </div>
                </div>
                <div class="study-overlay-stats-row">
                    <div>
                        <div class="study-overlay-stat-label">Sessions</div>
                        <div class="study-overlay-stat-value" id="sessionsCompleted">0</div>
                    </div>
                    <div>
                        <div class="study-overlay-stat-label">Total time</div>
                        <div class="study-overlay-stat-value" id="totalTimeLogged">0m</div>
                    </div>
                </div>
            </section>
            <!-- New task system: Streamer Tasks panel -->
            <!-- Shown when ?tasklist is present (filtered by ?tasklist&streamer=true to show only this panel) -->
            <section class="task-sys-card task-sys-card--streamer"
                     data-visible="<?php echo $show_new_streamer_panel ? 'true' : 'false'; ?>"
                     <?php if (!$show_new_streamer_panel) echo 'style="display:none"'; ?>>
                <div class="task-sys-card__header">
                    <span class="task-sys-card__dot" style="background:#48c78e"></span>
                    Streamer Tasks
                </div>
                <ul class="task-sys-card__list" id="newStreamerTaskList"></ul>
            </section>
            <!-- New task system: Viewer Tasks panel -->
            <!-- Shown when ?tasklist is present (hidden when ?tasklist&streamer=true) -->
            <section class="task-sys-card task-sys-card--viewer"
                     data-visible="<?php echo $show_new_viewer_panel ? 'true' : 'false'; ?>"
                     <?php if (!$show_new_viewer_panel) echo 'style="display:none"'; ?>>
                <div class="task-sys-card__header">
                    <span class="task-sys-card__dot" style="background:#3e8ed0"></span>
                    Viewer Tasks
                </div>
                <ul class="task-sys-card__list" id="newViewerTaskList"></ul>
            </section>
            <div id="taskRewardPopups"></div>
        </div>
    <?php endif; ?>
    <script>
        const overlayApiKey = <?php echo json_encode($api_key ?? null); ?>;
        const overlayUserName = <?php echo json_encode($username ?? 'Specter User'); ?>;
        const overlayErrorMessage = <?php echo json_encode($error_html ?? null); ?>;
        (function () {
            if (overlayErrorMessage) {
                const errorNode = document.getElementById('overlayErrorMessage');
                if (errorNode) {
                    errorNode.innerHTML = overlayErrorMessage;
                }
                return;
            }
            if (!overlayApiKey) {
                console.warn('Overlay missing API key.');
                return;
            }
            const overlayRoot = document.getElementById('overlayRoot');
            if (!overlayRoot) {
                return;
            }
            const connectionStatus = document.getElementById('connectionStatus');
            const phaseLabel = document.getElementById('phaseLabel');
            const timerDisplay = document.getElementById('timerDisplay');
            const statusText = document.getElementById('statusText');
            const sessionsCompletedEl = document.getElementById('sessionsCompleted');
            const totalTimeLoggedEl = document.getElementById('totalTimeLogged');
            const ringElement = document.getElementById('timerRingProgress');
            const circleRadius = 98;
            const circumference = 2 * Math.PI * circleRadius;
            ringElement.style.strokeDasharray = circumference;
            ringElement.style.strokeDashoffset = circumference;
            const defaultDurations = {
                focus: 60 * 60,
                micro: 5 * 60,
                recharge: 30 * 60
            };
            const phases = {
                focus: { label: 'Focus Sprint', status: 'Flow mode on', accent: '#ff9161' },
                micro: { label: 'Micro Break', status: 'Reignite energy', accent: '#6be9ff' },
                recharge: { label: 'Recharge Stretch', status: 'Stretch & hydrate', accent: '#b483ff' }
            };
            const timerState = {
                currentPhase: 'focus',
                remainingSeconds: defaultDurations.focus,
                totalDuration: defaultDurations.focus,
                timerRunning: false,
                timerPaused: false,
                countdownId: null,
                sessionsCompleted: 0,
                totalTimeLogged: 0,
                durations: { ...defaultDurations }
            };
            const timerStateStorageKey = `specter:working-study:timer:${overlayApiKey}`;
            let hasRestoredTimerState = false;
            const formatDuration = seconds => {
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
            const escapeHtml = text => {
                const div = document.createElement('div');
                div.textContent = text || '';
                return div.innerHTML;
            };
            const minutesToSeconds = minutes => {
                const numeric = Number(minutes);
                if (!Number.isFinite(numeric) || numeric <= 0) {
                    return null;
                }
                return Math.max(1, Math.round(numeric * 60));
            };
            const updateDurationsFromPayload = payload => {
                if (!payload) {
                    return;
                }
                if (payload.focus_minutes !== undefined && payload.focus_minutes !== null) {
                    const converted = minutesToSeconds(payload.focus_minutes);
                    if (converted) {
                        timerState.durations.focus = converted;
                    }
                }
                if (payload.micro_break_minutes !== undefined && payload.micro_break_minutes !== null) {
                    const converted = minutesToSeconds(payload.micro_break_minutes);
                    if (converted) {
                        timerState.durations.micro = converted;
                    }
                }
                if (payload.micro_minutes !== undefined && payload.micro_minutes !== null) {
                    const converted = minutesToSeconds(payload.micro_minutes);
                    if (converted) {
                        timerState.durations.micro = converted;
                    }
                }
                if (payload.recharge_break_minutes !== undefined && payload.recharge_break_minutes !== null) {
                    const converted = minutesToSeconds(payload.recharge_break_minutes);
                    if (converted) {
                        timerState.durations.recharge = converted;
                    }
                }
                if (payload.break_minutes !== undefined && payload.break_minutes !== null) {
                    const converted = minutesToSeconds(payload.break_minutes);
                    if (converted) {
                        timerState.durations.recharge = converted;
                    }
                }
            };
            const parseDurationOverride = payload => {
                if (!payload) {
                    return null;
                }
                if (payload.duration_seconds !== undefined && payload.duration_seconds !== null) {
                    const numeric = Number(payload.duration_seconds);
                    return Number.isFinite(numeric) && numeric > 0 ? numeric : null;
                }
                if (payload.duration_minutes !== undefined && payload.duration_minutes !== null) {
                    const numeric = Number(payload.duration_minutes);
                    return Number.isFinite(numeric) && numeric > 0 ? Math.round(numeric * 60) : null;
                }
                if (payload.duration !== undefined && payload.duration !== null) {
                    const numeric = Number(payload.duration);
                    return Number.isFinite(numeric) && numeric > 0 ? numeric : null;
                }
                if (payload.focus_minutes !== undefined && payload.focus_minutes !== null) {
                    return minutesToSeconds(payload.focus_minutes);
                }
                if (payload.break_minutes !== undefined && payload.break_minutes !== null) {
                    return minutesToSeconds(payload.break_minutes);
                }
                return null;
            };
            const updateDisplay = () => {
                const phase = timerState.currentPhase;
                phaseLabel.textContent = phases[phase].label;
                timerDisplay.textContent = formatDuration(timerState.remainingSeconds);
                statusText.textContent = getStatusLabel();
                document.documentElement.style.setProperty('--accent-color', phases[phase].accent);
                const progress = timerState.totalDuration > 0 ? timerState.remainingSeconds / timerState.totalDuration : 0;
                const offset = circumference * (1 - progress);
                ringElement.style.strokeDashoffset = isNaN(offset) ? circumference : offset;
            };
            const getStatusLabel = () => {
                if (timerState.timerPaused) {
                    return 'Paused';
                }
                if (timerState.timerRunning) {
                    return phases[timerState.currentPhase].status;
                }
                if (timerState.remainingSeconds === 0) {
                    return 'Stopped';
                }
                return 'Waiting';
            };
            const updateStatsDisplay = () => {
                sessionsCompletedEl.textContent = timerState.sessionsCompleted;
                totalTimeLoggedEl.textContent = formatTotalTime(timerState.totalTimeLogged);
            };
            const saveTimerState = () => {
                try {
                    if (typeof window === 'undefined' || !window.localStorage) {
                        return;
                    }
                    window.localStorage.setItem(timerStateStorageKey, JSON.stringify({
                        currentPhase: timerState.currentPhase,
                        remainingSeconds: timerState.remainingSeconds,
                        totalDuration: timerState.totalDuration,
                        timerRunning: timerState.timerRunning,
                        timerPaused: timerState.timerPaused,
                        sessionsCompleted: timerState.sessionsCompleted,
                        totalTimeLogged: timerState.totalTimeLogged,
                        durations: timerState.durations,
                        lastUpdatedAt: Date.now()
                    }));
                } catch (error) {
                    console.warn('[Overlay] Unable to persist timer state:', error);
                }
            };
            const clearSavedTimerState = () => {
                try {
                    if (typeof window === 'undefined' || !window.localStorage) {
                        return;
                    }
                    window.localStorage.removeItem(timerStateStorageKey);
                } catch (error) {
                    console.warn('[Overlay] Unable to clear timer state:', error);
                }
            };
            const emitTimerState = state => {
                if (socket && socket.connected) {
                    socket.emit('SPECTER_TIMER_STATE', { code: overlayApiKey, state });
                }
            };
            const emitTimerUpdate = () => {
                if (socket && socket.connected) {
                    socket.emit('SPECTER_TIMER_UPDATE', {
                        code: overlayApiKey,
                        phase: timerState.currentPhase,
                        remainingSeconds: timerState.remainingSeconds,
                        totalDurationForPhase: timerState.totalDuration,
                        timerRunning: timerState.timerRunning,
                        timerPaused: timerState.timerPaused,
                        phaseLabel: phases[timerState.currentPhase].label,
                        phaseStatus: phases[timerState.currentPhase].status,
                        phaseColor: phases[timerState.currentPhase].accent
                    });
                }
            };
            const emitSessionStats = () => {
                if (socket && socket.connected) {
                    socket.emit('SPECTER_SESSION_STATS', {
                        code: overlayApiKey,
                        sessionsCompleted: timerState.sessionsCompleted,
                        totalTimeLogged: timerState.totalTimeLogged
                    });
                }
            };
            const startCountdown = () => {
                clearInterval(timerState.countdownId);
                timerState.timerRunning = true;
                timerState.timerPaused = false;
                saveTimerState();
                emitTimerState('running');
                timerState.countdownId = setInterval(() => {
                    timerState.remainingSeconds = Math.max(0, timerState.remainingSeconds - 1);
                    if (timerState.remainingSeconds <= 0) {
                        clearInterval(timerState.countdownId);
                        timerState.countdownId = null;
                        finishPhase();
                        return;
                    }
                    updateDisplay();
                    saveTimerState();
                    emitTimerUpdate();
                }, 1000);
                updateDisplay();
                saveTimerState();
                emitTimerUpdate();
            };
            const finishPhase = () => {
                timerState.timerRunning = false;
                timerState.timerPaused = false;
                timerState.remainingSeconds = 0;
                timerState.sessionsCompleted += 1;
                timerState.totalTimeLogged += timerState.totalDuration;
                clearSavedTimerState();
                updateStatsDisplay();
                emitSessionStats();
                emitTimerState('stopped');
                updateDisplay();
                emitTimerUpdate();
            };
            const pauseTimer = () => {
                if (!timerState.timerRunning) {
                    return;
                }
                clearInterval(timerState.countdownId);
                timerState.countdownId = null;
                timerState.timerRunning = false;
                timerState.timerPaused = true;
                saveTimerState();
                emitTimerState('paused');
                updateDisplay();
                emitTimerUpdate();
            };
            const resumeTimer = () => {
                if (timerState.remainingSeconds <= 0 || timerState.timerRunning) {
                    return;
                }
                startCountdown();
            };
            const stopTimer = () => {
                clearInterval(timerState.countdownId);
                timerState.countdownId = null;
                timerState.timerRunning = false;
                timerState.timerPaused = false;
                timerState.remainingSeconds = 0;
                clearSavedTimerState();
                emitTimerState('stopped');
                updateDisplay();
                emitTimerUpdate();
            };
            const resetTimer = () => {
                clearInterval(timerState.countdownId);
                timerState.countdownId = null;
                timerState.timerRunning = false;
                timerState.timerPaused = false;
                timerState.remainingSeconds = timerState.durations[timerState.currentPhase];
                timerState.totalDuration = timerState.durations[timerState.currentPhase];
                saveTimerState();
                emitTimerState('stopped');
                updateDisplay();
                emitTimerUpdate();
            };
            const setPhase = (phase, options = { autoStart: true, duration: null }) => {
                if (!phases[phase]) {
                    return;
                }
                timerState.currentPhase = phase;
                const durationSeconds = typeof options.duration === 'number' && options.duration > 0
                    ? options.duration
                    : timerState.durations[phase];
                timerState.totalDuration = durationSeconds;
                timerState.remainingSeconds = durationSeconds;
                timerState.timerRunning = false;
                timerState.timerPaused = false;
                updateDisplay();
                emitTimerState('stopped');
                emitTimerUpdate();
                if (options.autoStart) {
                    startCountdown();
                } else {
                    saveTimerState();
                }
            };
            const restoreSavedTimerState = () => {
                try {
                    if (typeof window === 'undefined' || !window.localStorage) {
                        return false;
                    }
                    const raw = window.localStorage.getItem(timerStateStorageKey);
                    if (!raw) {
                        return false;
                    }
                    const saved = JSON.parse(raw);
                    if (!saved || !phases[saved.currentPhase]) {
                        clearSavedTimerState();
                        return false;
                    }

                    if (saved.durations && typeof saved.durations === 'object') {
                        const focus = Number(saved.durations.focus);
                        const micro = Number(saved.durations.micro);
                        const recharge = Number(saved.durations.recharge);
                        if (Number.isFinite(focus) && focus > 0) timerState.durations.focus = Math.round(focus);
                        if (Number.isFinite(micro) && micro > 0) timerState.durations.micro = Math.round(micro);
                        if (Number.isFinite(recharge) && recharge > 0) timerState.durations.recharge = Math.round(recharge);
                    }

                    timerState.currentPhase = saved.currentPhase;
                    timerState.totalDuration = Number.isFinite(saved.totalDuration) && saved.totalDuration > 0
                        ? Math.round(saved.totalDuration)
                        : timerState.durations[timerState.currentPhase];
                    timerState.remainingSeconds = Number.isFinite(saved.remainingSeconds) && saved.remainingSeconds >= 0
                        ? Math.round(saved.remainingSeconds)
                        : timerState.totalDuration;
                    timerState.sessionsCompleted = Number.isFinite(saved.sessionsCompleted) && saved.sessionsCompleted >= 0
                        ? Math.round(saved.sessionsCompleted)
                        : 0;
                    timerState.totalTimeLogged = Number.isFinite(saved.totalTimeLogged) && saved.totalTimeLogged >= 0
                        ? Math.round(saved.totalTimeLogged)
                        : 0;

                    const savedAt = Number(saved.lastUpdatedAt);
                    const elapsedSeconds = Number.isFinite(savedAt) && savedAt > 0
                        ? Math.max(0, Math.floor((Date.now() - savedAt) / 1000))
                        : 0;

                    if (saved.timerRunning) {
                        timerState.remainingSeconds = Math.max(0, timerState.remainingSeconds - elapsedSeconds);
                        timerState.timerRunning = false;
                        timerState.timerPaused = false;
                        if (timerState.remainingSeconds <= 0) {
                            timerState.remainingSeconds = 0;
                            timerState.sessionsCompleted += 1;
                            timerState.totalTimeLogged += timerState.totalDuration;
                            clearSavedTimerState();
                            updateStatsDisplay();
                            updateDisplay();
                            return true;
                        }
                        startCountdown();
                        return true;
                    }
                    timerState.timerRunning = false;
                    timerState.timerPaused = Boolean(saved.timerPaused);
                    if (timerState.remainingSeconds <= 0) {
                        clearSavedTimerState();
                    } else {
                        saveTimerState();
                    }
                    updateStatsDisplay();
                    updateDisplay();
                    return true;
                } catch (error) {
                    console.warn('[Overlay] Failed to restore timer state:', error);
                    clearSavedTimerState();
                    return false;
                }
            };
            const settingsEndpoint = `${window.location.pathname}?code=${encodeURIComponent(overlayApiKey)}&action=get_settings`;
            const loadSettingsFromAPI = async () => {
                try {
                    const response = await fetch(settingsEndpoint, { cache: 'no-store' });
                    const data = await response.json();
                    if (data.success && data.data) {
                        updateDurationsFromPayload(data.data);
                        timerState.durations.focus = timerState.durations.focus || defaultDurations.focus;
                        timerState.durations.micro = timerState.durations.micro || defaultDurations.micro;
                        timerState.durations.recharge = timerState.durations.recharge || defaultDurations.recharge;
                        if (!hasRestoredTimerState) {
                            setPhase(timerState.currentPhase, { autoStart: false });
                        } else if (!timerState.timerRunning && !timerState.timerPaused && timerState.remainingSeconds > 0) {
                            saveTimerState();
                            updateDisplay();
                        }
                    }
                } catch (error) {
                    console.error('[Overlay] Unable to load settings:', error);
                }
            };
            let socket = null;
            let reconnectAttempts = 0;
            let statsTicker = null;
            const setConnectionStatus = (text, state) => {
                if (!connectionStatus) return;
                connectionStatus.textContent = text;
                connectionStatus.dataset.state = state;
            };
            const startStatsTicker = () => {
                clearInterval(statsTicker);
                statsTicker = setInterval(() => {
                    emitSessionStats();
                }, 5000);
            };
            const stopStatsTicker = () => {
                if (statsTicker) {
                    clearInterval(statsTicker);
                    statsTicker = null;
                }
            };
            const scheduleReconnect = () => {
                reconnectAttempts += 1;
                const delay = Math.min(5000 * reconnectAttempts, 30000);
                setConnectionStatus('Reconnecting…', 'connecting');
                if (socket) {
                    socket.removeAllListeners();
                    socket = null;
                }
                setTimeout(connect, delay);
            };
            // ─── New channel task system helpers ───────────────────────────
            const channelTasksEndpoint = `${window.location.pathname}?code=${encodeURIComponent(overlayApiKey)}&action=get_channel_tasks`;
            const loadChannelTasks = async () => {
                // Only fetch if at least one task panel is visible
                const sPanel = document.getElementById('newStreamerTaskList');
                const vPanel = document.getElementById('newViewerTaskList');
                if (!sPanel && !vPanel) return;
                try {
                    const r = await fetch(channelTasksEndpoint);
                    const j = await r.json();
                    if (j.success) {
                        renderNewStreamerList(j.streamer_tasks || []);
                        renderNewViewerList(j.user_tasks || []);
                    }
                } catch (e) { console.warn('[Overlay] loadChannelTasks error', e); }
            };
            const renderNewStreamerList = (tasks) => {
                const list = document.getElementById('newStreamerTaskList');
                if (!list) return;
                list.innerHTML = '';
                tasks.forEach(t => newStreamerUpsert(t));
            };
            const renderNewViewerList = (tasks) => {
                const list = document.getElementById('newViewerTaskList');
                if (!list) return;
                list.innerHTML = '';
                tasks.forEach(t => newViewerUpsert(t));
            };
            const newStreamerUpsert = (task) => {
                const list = document.getElementById('newStreamerTaskList');
                if (!list) return;
                let li = document.getElementById('new-streamer-task-' + task.id);
                if (!li) { li = document.createElement('li'); li.id = 'new-streamer-task-' + task.id; list.appendChild(li); }
                const done = task.status === 'completed';
                li.className = 'task-sys-item' + (done ? ' is-done' : '');
                const pts = task.reward_points ? `<span class="task-sys-item__pts">${task.reward_points} pts</span>` : '';
                li.innerHTML = `<div class="task-sys-item__check"></div><div class="task-sys-item__body"><div class="task-sys-item__title">${escapeHtml(task.title)}</div>${task.category ? `<div class="task-sys-item__meta">${escapeHtml(task.category)}</div>` : ''}</div>${pts}`;
            };
            const newViewerUpsert = (task) => {
                const list = document.getElementById('newViewerTaskList');
                if (!list) return;
                let li = document.getElementById('new-viewer-task-' + task.id);
                if (!li) { li = document.createElement('li'); li.id = 'new-viewer-task-' + task.id; list.appendChild(li); }
                const done = task.status === 'completed';
                li.className = 'task-sys-item' + (done ? ' is-done' : '');
                const pts = task.reward_points ? `<span class="task-sys-item__pts">${task.reward_points} pts</span>` : '';
                li.innerHTML = `<div class="task-sys-item__check"></div><div class="task-sys-item__body"><div class="task-sys-item__title">${escapeHtml(task.title)}</div><div class="task-sys-item__meta">${escapeHtml(task.user_name || '')}</div></div>${pts}`;
            };
            const newSetDone = (taskId, owner) => {
                const ownerKey = String(owner || '').toLowerCase();
                if (ownerKey === 'streamer') {
                    const li = document.getElementById('new-streamer-task-' + taskId);
                    if (li) li.classList.add('is-done');
                    return;
                }
                if (ownerKey === 'user' || ownerKey === 'viewer') {
                    const li = document.getElementById('new-viewer-task-' + taskId);
                    if (li) li.classList.add('is-done');
                    return;
                }
                document.getElementById('new-streamer-task-' + taskId)?.classList.add('is-done');
                document.getElementById('new-viewer-task-' + taskId)?.classList.add('is-done');
            };
            const showTaskRewardPopup = (msg) => {
                const el = document.createElement('div');
                el.className = 'task-sys-reward';
                el.textContent = msg;
                const container = document.getElementById('taskRewardPopups') || document.body;
                container.appendChild(el);
                setTimeout(() => el.remove(), 5000);
            };
            // ────────────────────────────────────────────────────────────────
            const connect = () => {
                setConnectionStatus('Connecting…', 'connecting');
                console.log('[Overlay] Creating socket connection...');
                socket = io('wss://websocket.botofthespecter.com', { reconnection: false });
                console.log('[Overlay] Socket instance created, registering event listeners...');
                socket.on('connect', () => {
                    reconnectAttempts = 0;
                    console.log('[Overlay] Connected to socket server! ID:', socket.id);
                    setConnectionStatus('Connected', 'connected');
                    socket.emit('REGISTER', {
                        code: overlayApiKey,
                        channel: 'Overlay',
                        name: `Working Study Timer - ${overlayUserName}`
                    });
                    console.log('[Overlay] Sent REGISTER event for code:', overlayApiKey);
                    emitSessionStats();
                    loadSettingsFromAPI();
                    loadChannelTasks();
                    startStatsTicker();
                });
                console.log('[Overlay] Registering all WebSocket event listeners...');
                socket.on('disconnect', reason => {
                    console.warn('[Overlay] Disconnected:', reason);
                    setConnectionStatus('Disconnected', 'error');
                    stopStatsTicker();
                    scheduleReconnect();
                });
                socket.on('connect_error', error => {
                    console.error('[Overlay] WebSocket error:', error);
                    setConnectionStatus('Connection error', 'error');
                    stopStatsTicker();
                    scheduleReconnect();
                });
                socket.on('SUCCESS', payload => {
                    console.log('[Overlay] Received SUCCESS payload:', payload);
                    if (payload && typeof payload.message === 'string' && payload.message.toLowerCase().includes('registration')) {
                        console.log('[Overlay] Registration CONFIRMED by server');
                    }
                });
                socket.on('SPECTER_PHASE', payload => {
                    console.log('[Overlay] SPECTER_PHASE listener triggered');
                    const phaseKey = (payload.phase || payload.phase_key || '').toLowerCase();
                    if (!phaseKey || !phases[phaseKey]) {
                        return;
                    }
                    updateDurationsFromPayload(payload);
                    const autoStart = payload.auto_start !== undefined ? Boolean(payload.auto_start) : true;
                    const overrideDuration = parseDurationOverride(payload);
                    setPhase(phaseKey, { autoStart, duration: overrideDuration });
                });
                socket.on('SPECTER_SETTINGS_UPDATE', payload => {
                    console.log('[Overlay] SPECTER_SETTINGS_UPDATE listener triggered');
                    if (!payload) {
                        return;
                    }
                    updateDurationsFromPayload(payload);
                    if (!timerState.timerRunning && !timerState.timerPaused) {
                        timerState.totalDuration = timerState.durations[timerState.currentPhase];
                        timerState.remainingSeconds = timerState.totalDuration;
                        updateDisplay();
                        emitTimerUpdate();
                    }
                });
                socket.on('SPECTER_TIMER_CONTROL', payload => {
                    console.log('[Overlay] SPECTER_TIMER_CONTROL listener triggered');
                    handleTimerControl(payload);
                });
                socket.on('SPECTER_TIMER_COMMAND', payload => {
                    console.log('[Overlay] SPECTER_TIMER_COMMAND listener triggered (Bypass)');
                    handleTimerControl(payload);
                });
                const handleTimerControl = (payload) => {
                    console.log('[Overlay] Timer Control event received:', payload);
                    if (!payload) {
                        console.log('[Overlay] No payload in Timer Control event');
                        return;
                    }
                    const action = (payload.action || payload.command || '').toLowerCase();
                    console.log(`[Overlay] Parsed action: "${action}"`);
                    // Update timer durations from payload
                    updateDurationsFromPayload(payload);
                    const overrideDuration = parseDurationOverride(payload);
                    if (action === 'pause') {
                        pauseTimer();
                    } else if (action === 'resume') {
                        resumeTimer();
                    } else if (action === 'reset') {
                        resetTimer();
                    } else if (action === 'stop') {
                        stopTimer();
                    } else if (action === 'start') {
                        const durationOverride = typeof overrideDuration === 'number' ? overrideDuration : timerState.durations[timerState.currentPhase];
                        setPhase(timerState.currentPhase, { autoStart: true, duration: durationOverride });
                    }
                };
                socket.on('SPECTER_STATS_REQUEST', () => {
                    emitSessionStats();
                });
                // ── New channel task system events ──────────────────────────
                socket.on('TASK_LIST_SYNC', (d) => {
                    renderNewStreamerList(d.streamer_tasks || []);
                    renderNewViewerList(d.user_tasks || []);
                });
                socket.on('TASK_CREATE', (d) => {
                    const owner = String(d?.owner || d?.task?.owner || '').toLowerCase();
                    if (owner === 'streamer' || (!owner && !d?.task?.user_name)) newStreamerUpsert(d.task);
                    else newViewerUpsert(d.task);
                });
                socket.on('TASK_UPDATE', (d) => {
                    if (!d.task) return;
                    const owner = String(d?.owner || d?.task?.owner || '').toLowerCase();
                    if (owner === 'streamer' || (!owner && !d?.task?.user_name)) newStreamerUpsert(d.task);
                    else newViewerUpsert(d.task);
                });
                socket.on('TASK_COMPLETE', (d) => {
                    newSetDone(d.task_id, d.owner);
                });
                socket.on('TASK_APPROVE', (d) => {
                    // Approval means the task is accepted; no visual change here beyond completion
                });
                socket.on('TASK_REJECT', (d) => {
                    const el = document.getElementById('new-viewer-task-' + d.task_id);
                    if (el) el.style.opacity = '0.2';
                });
                socket.on('TASK_DELETE', (d) => {
                    const owner = String(d?.owner || '').toLowerCase();
                    if (owner === 'streamer') {
                        document.getElementById('new-streamer-task-' + d.task_id)?.remove();
                    } else if (owner === 'user' || owner === 'viewer') {
                        document.getElementById('new-viewer-task-' + d.task_id)?.remove();
                    } else {
                        document.getElementById('new-streamer-task-' + d.task_id)?.remove();
                        document.getElementById('new-viewer-task-' + d.task_id)?.remove();
                    }
                });
                socket.on('TASK_REWARD_CONFIRM', (d) => {
                    showTaskRewardPopup('\uD83C\uDFC6 ' + d.user_name + ' earned ' + d.points_awarded + ' pts!');
                });
                socket.on('SPECTER_SESSION_STATS', payload => {
                    if (!payload) return;
                    if (typeof payload.sessionsCompleted === 'number') {
                        timerState.sessionsCompleted = payload.sessionsCompleted;
                    }
                    if (typeof payload.totalTimeLogged === 'number') {
                        timerState.totalTimeLogged = payload.totalTimeLogged;
                    }
                    updateStatsDisplay();
                });
            };
            hasRestoredTimerState = restoreSavedTimerState();
            connect();
            updateStatsDisplay();
            updateDisplay();
        })();
    </script>
</body>
</html>