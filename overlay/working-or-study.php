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
$show_tasklist_panel = true;
$overlay_mode_class = '';
if ($has_timer_query && !$has_tasklist_query) {
    $show_tasklist_panel = false;
    $overlay_mode_class = 'study-overlay--timer-only';
} elseif ($has_tasklist_query && !$has_timer_query) {
    $show_timer_panel = false;
    $overlay_mode_class = 'study-overlay--tasks-only';
}

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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'working_study_overlay_tasks' => "
                CREATE TABLE IF NOT EXISTS working_study_overlay_tasks (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    username VARCHAR(255) NOT NULL,
                    task_id VARCHAR(255) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    priority VARCHAR(20) DEFAULT 'medium',
                    completed TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE (username, task_id),
                    INDEX idx_username (username)
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
            echo json_encode(['success' => true, 'data' => [
                'focus_minutes' => (int)$settings['focus_minutes'],
                'micro_break_minutes' => (int)$settings['micro_break_minutes'],
                'recharge_break_minutes' => (int)$settings['recharge_break_minutes']
            ]]);
        } else {
            echo json_encode(['success' => true, 'data' => [
                'focus_minutes' => 60,
                'micro_break_minutes' => 5,
                'recharge_break_minutes' => 30
            ]]);
        }
        exit;
    }
    if ($action === 'get_tasks') {
        $stmt = $user_db->prepare("SELECT task_id as id, title, priority, completed FROM working_study_overlay_tasks WHERE username = ? ORDER BY created_at DESC");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $user_db->error]);
            exit;
        }
        $stmt->bind_param("s", $username);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => 'Execute failed: ' . $stmt->error]);
            exit;
        }
        $result = $stmt->get_result();
        $tasks = [];
        while ($row = $result->fetch_assoc()) {
            $tasks[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'priority' => $row['priority'],
                'completed' => (bool)$row['completed']
            ];
        }
        $stmt->close();
        echo json_encode(['success' => true, 'data' => $tasks]);
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
    <script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
    <link rel="stylesheet" href="index.css">
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            min-height: 100vh;
            background: transparent;
            font-family: "Inter", "Segoe UI", system-ui, sans-serif;
            color: #f8fbff;
            display: flex;
            justify-content: center;
            align-items: center;
        }
    </style>
</head>
<body>
    <?php if ($error_html): ?>
        <div class="study-overlay-error-screen">
            <h1>Overlay unavailable</h1>
            <p id="overlayErrorMessage"><?php echo $error_html; ?></p>
        </div>
    <?php else: ?>
        <div class="study-overlay-root<?php echo $overlay_mode_class ? ' ' . htmlspecialchars($overlay_mode_class) : ''; ?>" id="overlayRoot">
            <section class="study-overlay-timer-card" data-visible="<?php echo $show_timer_panel ? 'true' : 'false'; ?>">
                <div class="study-overlay-status-row">
                    <span class="study-overlay-connection-status" id="connectionStatus" data-state="connecting">
                        <span class="dot"></span>
                        Connecting…
                    </span>
                    <span class="study-overlay-label">Overlay for <?php echo htmlspecialchars($username ?: 'Specter'); ?></span>
                </div>
                <div class="study-overlay-timer-ring">
                    <svg viewBox="0 0 220 220">
                        <circle class="study-overlay-ring-bg" cx="110" cy="110" r="98"></circle>
                        <circle class="study-overlay-ring-progress" id="timerRingProgress" cx="110" cy="110" r="98"></circle>
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
            <section class="study-overlay-task-card" data-visible="<?php echo $show_tasklist_panel ? 'true' : 'false'; ?>">
                <header>
                    <span class="study-overlay-task-title">Task List</span>
                </header>
                <div class="study-overlay-task-list" id="taskList">
                    <p class="study-overlay-empty-state">Loading tasks…</p>
                </div>
                <div class="study-overlay-task-footer">
                    <span id="taskCount">0 tasks</span>
                    <span id="taskUpdated">Updated: --:--</span>
                </div>
            </section>
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
            const taskListEl = document.getElementById('taskList');
            const taskCountEl = document.getElementById('taskCount');
            const taskUpdatedEl = document.getElementById('taskUpdated');
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
                if (payload.recharge_break_minutes !== undefined && payload.recharge_break_minutes !== null) {
                    const converted = minutesToSeconds(payload.recharge_break_minutes);
                    if (converted) {
                        timerState.durations.recharge = converted;
                    }
                }
                if (payload.break_minutes !== undefined && payload.break_minutes !== null) {
                    const converted = minutesToSeconds(payload.break_minutes);
                    if (converted) {
                        timerState.durations.micro = converted;
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
                    emitTimerUpdate();
                }, 1000);
                updateDisplay();
                emitTimerUpdate();
            };
            const finishPhase = () => {
                timerState.timerRunning = false;
                timerState.timerPaused = false;
                timerState.remainingSeconds = 0;
                timerState.sessionsCompleted += 1;
                timerState.totalTimeLogged += timerState.totalDuration;
                updateStatsDisplay();
                emitSessionStats();
                emitTimerState('stopped');
                updateDisplay();
            };
            const pauseTimer = () => {
                if (!timerState.timerRunning) {
                    return;
                }
                clearInterval(timerState.countdownId);
                timerState.countdownId = null;
                timerState.timerRunning = false;
                timerState.timerPaused = true;
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
                }
            };
            const getCurrentTimeStamp = () => {
                const now = new Date();
                return `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
            };
            const renderTasks = tasks => {
                taskListEl.innerHTML = '';
                if (!tasks || !tasks.length) {
                    taskListEl.innerHTML = '<p class="study-overlay-empty-state">No tasks yet.</p>';
                    taskCountEl.textContent = '0 tasks';
                    taskUpdatedEl.textContent = `Updated: ${getCurrentTimeStamp()}`;
                    return;
                }
                tasks.forEach(task => {
                    const node = document.createElement('article');
                    node.className = 'study-overlay-task-item' + (task.completed ? ' completed' : '');
                    node.innerHTML = `
                        <p class="study-overlay-task-text">${escapeHtml(task.title)}</p>
                        <div class="study-overlay-task-meta">
                            ${task.priority ? `<span class="study-overlay-task-priority ${task.priority.toLowerCase()}">${task.priority}</span>` : ''}
                            ${task.completed ? '<span>Completed</span>' : ''}
                        </div>
                    `;
                    taskListEl.appendChild(node);
                });
                taskCountEl.textContent = `${tasks.length} task${tasks.length === 1 ? '' : 's'}`;
                taskUpdatedEl.textContent = `Updated: ${getCurrentTimeStamp()}`;
            };
            const tasksEndpoint = `${window.location.pathname}?code=${encodeURIComponent(overlayApiKey)}&action=get_tasks`;
            const settingsEndpoint = `${window.location.pathname}?code=${encodeURIComponent(overlayApiKey)}&action=get_settings`;
            const loadTasksFromAPI = async () => {
                try {
                    const response = await fetch(tasksEndpoint, { cache: 'no-store' });
                    const data = await response.json();
                    if (data.success) {
                        renderTasks(data.data);
                    } else {
                        console.error('[Overlay] Task list failed:', data.error);
                    }
                } catch (error) {
                    console.error('[Overlay] Unable to load tasks:', error);
                }
            };
            const loadSettingsFromAPI = async () => {
                try {
                    const response = await fetch(settingsEndpoint, { cache: 'no-store' });
                    const data = await response.json();
                    if (data.success && data.data) {
                        updateDurationsFromPayload(data.data);
                        timerState.durations.focus = timerState.durations.focus || defaultDurations.focus;
                        timerState.durations.micro = timerState.durations.micro || defaultDurations.micro;
                        timerState.durations.recharge = timerState.durations.recharge || defaultDurations.recharge;
                        setPhase(timerState.currentPhase, { autoStart: false });
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
            const connect = () => {
                setConnectionStatus('Connecting…', 'connecting');
                socket = io('wss://websocket.botofthespecter.com', { reconnection: false });
                socket.on('connect', () => {
                    reconnectAttempts = 0;
                    setConnectionStatus('Connected', 'connected');
                    socket.emit('REGISTER', {
                        code: overlayApiKey,
                        channel: 'Overlay',
                        name: `Working Study Timer - ${overlayUserName}`
                    });
                    emitSessionStats();
                    loadSettingsFromAPI();
                    loadTasksFromAPI();
                    startStatsTicker();
                });
                socket.on('disconnect', reason => {
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
                    if (payload && typeof payload.message === 'string' && payload.message.toLowerCase().includes('registration')) {
                        setConnectionStatus('Registered', 'connected');
                    }
                });
                socket.on('SPECTER_PHASE', payload => {
                    const phaseKey = (payload.phase || payload.phase_key || '').toLowerCase();
                    if (!phaseKey || !phases[phaseKey]) {
                        return;
                    }
                    updateDurationsFromPayload(payload);
                    const autoStart = payload.auto_start !== undefined ? Boolean(payload.auto_start) : true;
                    const overrideDuration = parseDurationOverride(payload);
                    setPhase(phaseKey, { autoStart, duration: overrideDuration });
                });
                socket.on('SPECTER_TIMER_CONTROL', payload => {
                    if (!payload) return;
                    const action = (payload.action || payload.command || '').toLowerCase();
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
                });
                socket.on('SPECTER_STATS_REQUEST', () => {
                    emitSessionStats();
                });
                socket.on('SPECTER_TASKLIST_UPDATE', () => {
                    loadTasksFromAPI();
                });
                socket.on('SPECTER_TASKLIST', () => {
                    loadTasksFromAPI();
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
            connect();
            renderTasks([]);
            updateStatsDisplay();
            updateDisplay();
        })();
    </script>
</body>
</html>