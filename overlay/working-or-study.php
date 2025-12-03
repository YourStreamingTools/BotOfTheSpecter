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
        // Ensure required tables exist in user database
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
    // Clear any buffered output before sending JSON
    ob_end_clean();
    header('Content-Type: application/json');
    $action = $_GET['action'];
    if ($error_html) {
        echo json_encode(['success' => false, 'error' => strip_tags($error_html)]);
        exit;
    }
    if ($action === 'get_settings') {
        // Load timer settings from database (global settings)
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
        // Load all tasks for current user from database
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
            height: 100vh;
            overflow: hidden;
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
            padding: 0;
        }
        .has-text-white {
            color: #f8fbff !important;
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
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .timer-card {
            width: var(--timer-width);
            padding: 40px;
            border-radius: 32px;
            background: rgba(20, 20, 30, 0.95);
            border: 2px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
            display: flex;
            flex-direction: column;
            gap: 24px;
            text-align: center;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }
        .timer-card::before {
            display: none;
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
        }
        .timer-ring svg {
            width: 100%;
            height: 100%;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.3));
            transform: rotate(-90deg);
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
            width: 100%;
            z-index: 10;
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
            color: #f8fbff;
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
        .stats-large-display {
            display: flex;
            justify-content: space-around;
            align-items: center;
            gap: 24px;
            padding: 20px 0;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .stat-large-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        .stat-large-label {
            font-size: 0.75rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.6);
            font-weight: 500;
        }
        .stat-large-value {
            font-size: clamp(32px, 6vw, 48px);
            font-weight: 700;
            color: var(--accent-color);
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
            font-variant-numeric: tabular-nums;
        }
        /* Task List Overlay Styles */
        .tasklist-wrapper {
            width: 100%;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .tasklist-card {
            width: min(500px, 90vw);
            max-height: 90vh;
            padding: 24px;
            border-radius: 20px;
            background: rgba(20, 20, 30, 0.95);
            border: 2px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
            display: flex;
            flex-direction: column;
            gap: 16px;
            backdrop-filter: blur(10px);
        }
        .tasklist-header {
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 12px;
        }
        .tasklist-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #f8fbff;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin: 0;
        }
        .tasklist-subtitle {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.6);
            margin-top: 4px;
        }
        .tasklist-container {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding-right: 8px;
        }
        .tasklist-container::-webkit-scrollbar {
            width: 6px;
        }
        .tasklist-container::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 3px;
        }
        .tasklist-container::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
            transition: background 0.3s ease;
        }
        .tasklist-container::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        .task-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            transition: all 0.3s ease;
            animation: slideIn 0.3s ease-out;
        }
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        .task-item:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.15);
        }
        .task-item.completed {
            opacity: 0.7;
        }
        .task-item.completed .task-text {
            text-decoration: line-through;
            color: rgba(255, 255, 255, 0.6);
        }
        .task-checkbox {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            min-width: 20px;
            margin-top: 2px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            background: transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .task-checkbox:hover {
            border-color: var(--accent-color);
            background: rgba(255, 255, 255, 0.05);
        }
        .task-checkbox.checked {
            background: #2ecc71;
            border-color: #2ecc71;
        }
        .task-checkbox.checked::after {
            content: '✓';
            color: #ffffff;
            font-weight: 700;
            font-size: 12px;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        }
        .task-content {
            flex: 1;
            min-width: 0;
        }
        .task-text {
            font-size: 0.95rem;
            color: #f8fbff;
            word-wrap: break-word;
            word-break: break-word;
            margin: 0;
        }
        .task-meta {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.5);
            margin-top: 4px;
            display: flex;
            gap: 12px;
        }
        .task-priority {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 6px;
            font-weight: 600;
        }
        .task-priority.high {
            background: rgba(255, 145, 97, 0.2);
            color: #ff9161;
        }
        .task-priority.medium {
            background: rgba(107, 233, 255, 0.2);
            color: #6be9ff;
        }
        .task-priority.low {
            background: rgba(180, 131, 255, 0.2);
            color: #b483ff;
        }
        .tasklist-empty {
            text-align: center;
            padding: 40px 20px;
            color: rgba(255, 255, 255, 0.5);
        }
        .tasklist-empty-icon {
            font-size: 2.5rem;
            margin-bottom: 12px;
            opacity: 0.4;
        }
        .tasklist-empty-text {
            font-size: 0.95rem;
        }
        .tasklist-footer {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.4);
            text-align: center;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 12px;
        }
    </style>
</head>
<body>
    <div class="placeholder" id="timerPlaceholder">Append <code>&timer</code> to the overlay URL to show the Specter timer.</div>
    <div class="placeholder" id="tasklistPlaceholder">Append <code>&tasklist</code> to the overlay URL to show the Specter task list.</div>
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
            <div class="stats-large-display" id="statsLargeDisplay">
                <div class="stat-large-item">
                    <span class="stat-large-label has-text-white">Current Session</span>
                    <span class="stat-large-value" id="sessionsCompletedLarge">0</span>
                </div>
                <div class="stat-large-item">
                    <span class="stat-large-label has-text-white">Focus Time</span>
                    <span class="stat-large-value" id="totalTimeLoggedLarge">0h 0m</span>
                </div>
            </div>
        </div>
    </div>
    <div class="tasklist-wrapper" id="tasklistWrapper" style="display: none;">
        <div class="tasklist-card" id="tasklistCard">
            <div class="tasklist-header">
                <h2 class="tasklist-title">Task List</h2>
                <p class="tasklist-subtitle" id="tasklistSubtitle">0 tasks</p>
            </div>
            <div class="tasklist-container" id="tasklistContainer">
                <div class="tasklist-empty">
                    <div class="tasklist-empty-icon">📋</div>
                    <div class="tasklist-empty-text">No tasks yet. Get started!</div>
                </div>
            </div>
            <div class="tasklist-footer" id="tasklistFooter">
                Updated: <span id="lastUpdate">--:--</span>
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
            const microSeconds = minutesToSeconds(parseMinutesParam(urlParams.get('break_minutes'), 5));
            const rechargeSeconds = minutesToSeconds(parseMinutesParam(urlParams.get('recharge_minutes'), 30));
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
            let timerRunning = false;
            let timerPaused = false;
            const phaseLabel = document.getElementById('phaseLabel');
            const statusChip = document.getElementById('statusChip');
            const statusText = document.getElementById('statusText');
            const timerDisplay = document.getElementById('timerDisplay');
            const timerRingProgress = document.getElementById('timerRingProgress');
            const sessionsCompletedLargeEl = document.getElementById('sessionsCompletedLarge');
            const totalTimeLoggedLargeEl = document.getElementById('totalTimeLoggedLarge');
            const circumference = 2 * Math.PI * 130;
            // Task List State and Functions
            let taskList = [];
            let isStreamerView = false;
            const tasklistWrapper = document.getElementById('tasklistWrapper');
            const tasklistPlaceholder = document.getElementById('tasklistPlaceholder');
            const tasklistContainer = document.getElementById('tasklistContainer');
            const tasklistSubtitle = document.getElementById('tasklistSubtitle');
            const lastUpdate = document.getElementById('lastUpdate');
            const maxTasksStreamer = 3;
            const maxTasksUsers = 5;
            const getMaxTasks = () => isStreamerView ? maxTasksStreamer : maxTasksUsers;
            const updateTaskListHeight = () => {
                const container = document.getElementById('tasklistContainer');
                const maxTasks = getMaxTasks();
                const itemHeight = 60; // approximate height per task item
                const maxHeight = maxTasks * itemHeight;
                container.style.maxHeight = `${maxHeight}px`;
            };
            const formatUpdateTime = () => {
                const now = new Date();
                return `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
            };
            const renderTaskList = () => {
                tasklistContainer.innerHTML = '';
                
                if (!taskList || taskList.length === 0) {
                    tasklistContainer.innerHTML = `
                        <div class="tasklist-empty">
                            <div class="tasklist-empty-icon">📋</div>
                            <div class="tasklist-empty-text">No tasks yet. Get started!</div>
                        </div>
                    `;
                    tasklistSubtitle.textContent = '0 tasks';
                    return;
                }
                tasklistSubtitle.textContent = `${taskList.length} task${taskList.length !== 1 ? 's' : ''}`;
                taskList.forEach((task, index) => {
                    const taskElement = document.createElement('div');
                    taskElement.className = `task-item${task.completed ? ' completed' : ''}`;
                    taskElement.innerHTML = `
                        <div class="task-checkbox${task.completed ? ' checked' : ''}" data-task-id="${task.id || index}"></div>
                        <div class="task-content">
                            <p class="task-text">${escapeHtml(task.title)}</p>
                            <div class="task-meta">
                                ${task.priority ? `<span class="task-priority ${task.priority.toLowerCase()}">${task.priority}</span>` : ''}
                                ${task.dueDate ? `<span class="task-due">${task.dueDate}</span>` : ''}
                            </div>
                        </div>
                    `;
                    // Add click handler for checkbox (if not streamer view, allow local interaction)
                    const checkbox = taskElement.querySelector('.task-checkbox');
                    if (!isStreamerView) {
                        checkbox.style.cursor = 'pointer';
                        checkbox.addEventListener('click', () => {
                            task.completed = !task.completed;
                            renderTaskList();
                            emitTaskListUpdate();
                        });
                    } else {
                        checkbox.style.cursor = 'default';
                        checkbox.style.opacity = '0.5';
                    }
                    tasklistContainer.appendChild(taskElement);
                });
                lastUpdate.textContent = formatUpdateTime();
                updateTaskListHeight();
            };
            const updateTaskList = (tasks, streamer = false) => {
                taskList = tasks || [];
                isStreamerView = streamer;
                renderTaskList();
            };
            const emitTaskListUpdate = () => {
                if (socket && socket.connected && !isStreamerView) {
                    socket.emit('SPECTER_TASKLIST_UPDATE', {
                        code: apiCode,
                        tasks: taskList,
                        timestamp: Date.now()
                    });
                }
            };
            const escapeHtml = (text) => {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            };
            const emitTimerState = (state) => {
                if (socket && socket.connected) {
                    socket.emit('SPECTER_TIMER_STATE', { state, code: apiCode });
                }
            };
            const emitSessionStats = () => {
                if (socket && socket.connected) {
                    socket.emit('SPECTER_SESSION_STATS', {
                        code: apiCode,
                        sessionsCompleted,
                        totalTimeLogged
                    });
                }
            };
            const emitTimerUpdate = () => {
                if (socket && socket.connected) {
                    socket.emit('SPECTER_TIMER_UPDATE', {
                        code: apiCode,
                        phase: currentPhase,
                        remainingSeconds,
                        totalDurationForPhase,
                        timerRunning,
                        timerPaused,
                        phaseLabel: phases[currentPhase].label,
                        phaseStatus: phases[currentPhase].status,
                        phaseColor: phases[currentPhase].accent
                    });
                }
            };
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
                timerRunning = true;
                timerPaused = false;
                statusChip.classList.add('active');
                emitTimerState('running');
                countdownId = setInterval(() => {
                    if (remainingSeconds <= 0) {
                        clearCountdown();
                        statusChip.classList.remove('active');
                        statusText.textContent = 'Session complete — choose next phase';
                        playNotificationSound();
                        sessionsCompleted += 1;
                        totalTimeLogged += totalDurationForPhase;
                        updateStats();
                        emitSessionStats();
                        timerRunning = false;
                        emitTimerState('stopped');
                        return;
                    }
                    remainingSeconds -= 1;
                    updateDisplay();
                    emitTimerUpdate();
                }, 1000);
                updateDisplay();
            };
            const pauseTimer = () => {
                clearCountdown();
                timerRunning = false;
                timerPaused = true;
                statusChip.classList.remove('active');
                statusText.textContent = 'Paused — resume when ready';
                emitTimerState('paused');
                updateDisplay();
                emitTimerUpdate();
            };
            const resumeTimer = () => {
                if (remainingSeconds <= 0) return;
                timerRunning = true;
                timerPaused = false;
                statusChip.classList.add('active');
                emitTimerState('running');
                countdownId = setInterval(() => {
                    if (remainingSeconds <= 0) {
                        clearCountdown();
                        statusChip.classList.remove('active');
                        statusText.textContent = 'Session complete — choose next phase';
                        playNotificationSound();
                        sessionsCompleted += 1;
                        totalTimeLogged += totalDurationForPhase;
                        updateStats();
                        emitSessionStats();
                        timerRunning = false;
                        emitTimerState('stopped');
                        return;
                    }
                    remainingSeconds -= 1;
                    updateDisplay();
                    emitTimerUpdate();
                }, 1000);
                updateDisplay();
                emitTimerUpdate();
            };
            const resetTimer = () => {
                clearCountdown();
                timerRunning = false;
                timerPaused = false;
                statusChip.classList.remove('active');
                remainingSeconds = defaultDurations[currentPhase];
                totalDurationForPhase = defaultDurations[currentPhase];
                statusText.textContent = 'Ready for another round';
                emitTimerState('stopped');
                updateDisplay();
                emitTimerUpdate();
            };
            const stopTimer = () => {
                clearCountdown();
                timerRunning = false;
                timerPaused = false;
                statusChip.classList.remove('active');
                remainingSeconds = 0;
                updateDisplay();
                statusText.textContent = 'Timer stopped';
                emitTimerState('stopped');
                emitTimerUpdate();
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
                sessionsCompletedLargeEl.textContent = sessionsCompleted;
                totalTimeLoggedLargeEl.textContent = formatTotalTime(totalTimeLogged);
            };
            const setPhase = (phase, { autoStart = true, duration = null } = {}) => {
                if (!phases[phase]) return;
                currentPhase = phase;
                const durationSeconds = typeof duration === 'number' && Number.isFinite(duration) && duration > 0 ? duration : defaultDurations[phase];
                phases[phase] = { ...phases[phase], duration: durationSeconds };
                remainingSeconds = durationSeconds;
                totalDurationForPhase = durationSeconds;
                timerRunning = false;
                timerPaused = false;
                updateDisplay();
                if (autoStart) {
                    startCountdown();
                } else {
                    emitTimerState('stopped');
                }
            };
            window.SpecterWorkingStudyTimer = {
                startPhase: (phaseKey, options) => setPhase(phaseKey, options),
                pause: pauseTimer,
                resume: resumeTimer,
                reset: resetTimer,
                stop: stopTimer
            };
            // Helper function for boolean parsing
            const parseBool = (value, fallback = false) => {
                if (value === undefined || value === null) return fallback;
                const normalized = String(value).toLowerCase();
                if (['1', 'true', 'yes', 'on'].includes(normalized)) return true;
                if (['0', 'false', 'no', 'off'].includes(normalized)) return false;
                return fallback;
            };
            const timerWrapper = document.getElementById('timerWrapper');
            const timerPlaceholder = document.getElementById('timerPlaceholder');
            const showTimer = urlParams.has('timer');
            const showTasklist = urlParams.has('tasklist');
            // Handle overlay mode selection
            if (!showTimer && !showTasklist) {
                timerWrapper.style.display = 'none';
                tasklistWrapper.style.display = 'none';
                timerPlaceholder.style.display = 'block';
                tasklistPlaceholder.style.display = 'block';
                return;
            }
            if (showTasklist) {
                timerWrapper.style.display = 'none';
                timerPlaceholder.style.display = 'none';
                tasklistWrapper.style.display = 'flex';
                tasklistPlaceholder.style.display = 'none';
                isStreamerView = urlParams.has('streamer') ? parseBool(urlParams.get('streamer'), true) : true;
                updateTaskListHeight();
                // Initialize empty task list
                updateTaskList([], isStreamerView);
            } else if (showTimer) {
                timerWrapper.style.display = 'flex';
                timerPlaceholder.style.display = 'none';
                tasklistWrapper.style.display = 'none';
                tasklistPlaceholder.style.display = 'none';
            }
            const apiCode = urlParams.get('code');
            if (!apiCode) {
                console.warn('Overlay missing viewer API code; websocket control disabled.');
                if (showTimer) {
                    timerPlaceholder.style.display = 'none';
                    timerWrapper.style.display = 'flex';
                }
                return;
            }
            if (!showTimer && !showTasklist) return;
            const socketUrl = 'wss://websocket.botofthespecter.com';
            let socket;
            let attempts = 0;
            if (showTimer) {
                timerPlaceholder.style.display = 'none';
                timerWrapper.style.display = 'flex';
                setPhase('focus', { autoStart: false });
                updateStats();
            }
            // Determine connection name based on mode - format: "[Type]"
            const connectionName = showTasklist ? 'Working Study Task List' : 'Working Study Timer';
            // Dashboard will emit SPECTER_TASKLIST events to the Overlay channel as well
            const channelName = 'Overlay';
            const scheduleReconnect = () => {
                attempts += 1;
                const delay = Math.min(5000 * attempts, 30000);
                console.log(`[Overlay] Scheduling reconnect in ${delay}ms (attempt ${attempts})`);
                if (socket) {
                    socket.removeAllListeners();
                    socket = null;
                }
                setTimeout(connect, delay);
            };
            // Load settings from dashboard API
            const loadSettingsFromAPI = async () => {
                try {
                    const overlayUrl = `${window.location.pathname}?code=${encodeURIComponent(apiCode)}&action=get_settings`;
                    const response = await fetch(overlayUrl);
                    const result = await response.json();
                    if (result.success && result.data) {
                        // Update default durations from database
                        defaultDurations.focus = minutesToSeconds(result.data.focus_minutes);
                        defaultDurations.micro = minutesToSeconds(result.data.micro_break_minutes);
                        defaultDurations.recharge = minutesToSeconds(result.data.recharge_break_minutes);
                        // Update phases with new durations
                        phases.focus.duration = defaultDurations.focus;
                        phases.micro.duration = defaultDurations.micro;
                        phases.recharge.duration = defaultDurations.recharge;
                        console.log('[Overlay] Settings loaded from API:', result.data);
                        updateDisplay();
                    } else if (!result.success) {
                        console.error('[Overlay] Error loading settings:', result.error);
                    }
                } catch (error) {
                    console.error('[Overlay] Error loading settings from API:', error);
                }
            };
            // Load tasks from overlay's own API (uses database.php connection)
            const loadTasksFromAPI = async () => {
                try {
                    const overlayUrl = `${window.location.pathname}?code=${encodeURIComponent(apiCode)}&action=get_tasks`;
                    const response = await fetch(overlayUrl);
                    const result = await response.json();
                    if (result.success && result.data) {
                        console.log('[Overlay] Tasks loaded from API:', result.data.length, 'tasks');
                        updateTaskList(result.data, isStreamerView);
                    } else if (!result.success) {
                        console.error('[Overlay] Error loading tasks:', result.error);
                    }
                } catch (error) {
                    console.error('[Overlay] Error loading tasks from API:', error);
                }
            };
            const connect = () => {
                console.log(`[Overlay] Connecting to WebSocket as "${connectionName}"...`);
                socket = io(socketUrl, { reconnection: false });
                socket.on('connect', () => {
                    attempts = 0;
                    console.log(`[Overlay] ✓ WebSocket connected, registering as "${connectionName}"`);
                    socket.emit('REGISTER', { 
                        code: apiCode, 
                        channel: channelName, 
                        name: connectionName
                    });
                    // Emit stats immediately on connect
                    if (showTimer) {
                        emitSessionStats();
                        loadSettingsFromAPI();
                    }
                    // Load tasks from API on connect
                    if (showTasklist) {
                        loadTasksFromAPI();
                    }
                });
                socket.on('disconnect', (reason) => {
                    console.log(`[Overlay] ✗ WebSocket disconnected: ${reason}`);
                    scheduleReconnect();
                });
                socket.on('connect_error', (error) => {
                    console.error(`[Overlay] Connection error: ${error.message}`);
                    scheduleReconnect();
                });
                // Timer-specific handlers
                if (showTimer) {
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
                    socket.on('SPECTER_STATS_REQUEST', payload => {
                        console.log('[Overlay] Dashboard requesting session stats');
                        emitSessionStats();
                    });
                }
                // Task list handlers
                if (showTasklist) {
                    socket.on('SPECTER_TASKLIST', payload => {
                        console.log('[Overlay] Received task list update via WebSocket');
                        // Fetch fresh tasks from database when notified of updates
                        loadTasksFromAPI();
                    });
                }
                socket.onAny((event, ...args) => {
                    console.debug('[Overlay] WebSocket event:', event, args);
                });
            };
            // Emit stats every 5 seconds to keep dashboard updated (timer mode only)
            if (showTimer) {
                setInterval(() => {
                    if (socket && socket.connected) {
                        emitSessionStats();
                    }
                }, 5000);
            }
            connect();
        })();
    </script>
</body>
</html>