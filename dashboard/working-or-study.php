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

// Include files for database and user data
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

// Handle API requests for working study settings and tasks
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    // Verify database connection exists
    if (!isset($db) || !$db) {
        echo json_encode(['success' => false, 'error' => 'Database connection not available']);
        exit;
    }
    if ($action === 'get_settings') {
        // Load timer settings from database
        $stmt = $db->prepare("SELECT focus_minutes, micro_break_minutes, recharge_break_minutes FROM working_study_overlay_settings LIMIT 1");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $db->error]);
            exit;
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $settings = $result->fetch_assoc();
        if (!$settings) {
            // Return defaults if no settings exist
            $settings = [
                'focus_minutes' => 60,
                'micro_break_minutes' => 5,
                'recharge_break_minutes' => 30
            ];
        }
        echo json_encode(['success' => true, 'data' => $settings]);
        $stmt->close();
        exit;
    }
    if ($action === 'save_settings') {
        // Save timer settings to database
        $focus = intval($_POST['focus_minutes'] ?? 60);
        $micro = intval($_POST['micro_break_minutes'] ?? 5);
        $recharge = intval($_POST['recharge_break_minutes'] ?? 30);
        $stmt = $db->prepare("UPDATE working_study_overlay_settings SET focus_minutes = ?, micro_break_minutes = ?, recharge_break_minutes = ? WHERE id = 1");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $db->error]);
            exit;
        }
        $stmt->bind_param("iii", $focus, $micro, $recharge);
        $success = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $success]);
        exit;
    }
    if ($action === 'get_tasks') {
        // Load all tasks for current user from database
        $username = (string) $_SESSION['username'];
        $stmt = $db->prepare("SELECT task_id as id, title, priority, completed FROM working_study_overlay_tasks WHERE username = ? ORDER BY created_at DESC");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $db->error]);
            exit;
        }
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $tasks = [];
        while ($row = $result->fetch_assoc()) {
            $tasks[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'priority' => $row['priority'],
                'completed' => (bool) $row['completed']
            ];
        }
        $stmt->close();
        echo json_encode(['success' => true, 'data' => $tasks]);
        exit;
    }
    if ($action === 'save_tasks') {
        // Save all tasks for current user to database
        $username = (string) $_SESSION['username'];
        $tasks = json_decode($_POST['tasks'] ?? '[]', true);
        if (!is_array($tasks)) {
            echo json_encode(['success' => false, 'error' => 'Invalid tasks format']);
            exit;
        }
        // Clear existing tasks for this user only
        $stmt = $db->prepare("DELETE FROM working_study_overlay_tasks WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->close();
        }
        // Insert new tasks for this user
        $stmt = $db->prepare("INSERT INTO working_study_overlay_tasks (username, task_id, title, priority, completed) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $db->error]);
            exit;
        }
        $success = true;
        foreach ($tasks as $task) {
            $task_id = (string) ($task['id'] ?? '');
            $title = (string) ($task['title'] ?? '');
            $priority = (string) ($task['priority'] ?? 'medium');
            $completed = $task['completed'] ? 1 : 0;
            $stmt->bind_param("ssssi", $username, $task_id, $title, $priority, $completed);
            if (!$stmt->execute()) {
                $success = false;
                break;
            }
        }
        $stmt->close();
        echo json_encode(['success' => $success]);
        exit;
    }
}

// Load initial settings for page initialization
$initialSettings = ['focus_minutes' => 60, 'micro_break_minutes' => 5, 'recharge_break_minutes' => 30];
if ($db) {
    $stmt = $db->prepare("SELECT focus_minutes, micro_break_minutes, recharge_break_minutes FROM working_study_overlay_settings LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $initialSettings = $row;
        }
        $stmt->close();
    }
}

// Load initial tasks for page initialization
$initialTasks = [];
if ($db && isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $stmt = $db->prepare("SELECT task_id as id, title, priority, completed FROM working_study_overlay_tasks WHERE username = ? ORDER BY created_at DESC");
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $initialTasks[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'priority' => $row['priority'],
                'completed' => (bool) $row['completed']
            ];
        }
        $stmt->close();
    }
}

ob_start();
?>
<section class="section">
    <div class="card">
        <header class="card-header">
            <p class="card-header-title">
                Working / Study Overlay Control
            </p>
            <div class="buttons">
                <button type="button" class="button is-primary" id="copyOverlayLinkBtn">
                    <span class="icon">
                        <i class="fas fa-copy" aria-hidden="true"></i>
                    </span>
                    <span>Copy Timer Overlay Link</span>
                </button>
            </div>
        </header>
        <div class="card-content">
            <div class="content">
                <p><strong>Specter Working/Study Timer Overlay</strong></p>
                <p>Control a professional productivity timer overlay displayed on your stream. Track focus sessions,
                    breaks, and recharge time with real-time visual feedback.</p>
                <ul>
                    <li>Visual progress ring that depletes with time</li>
                    <li>Dynamic color coding for each phase: Orange (focus), Cyan (break), Purple (recharge)</li>
                    <li>Session counter and total time logged statistics</li>
                    <li>Sound notifications when phases complete</li>
                    <li>Real-time synchronization via WebSocket</li>
                    <li>Responsive design that scales for stream overlays</li>
                </ul>
            </div>
            <div class="columns is-multiline">
                <div class="column is-full">
                    <h3 class="title is-6">Duration Settings</h3>
                    <div class="columns">
                        <div class="column is-one-third">
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
                                            <input id="focusLengthMinutes" class="input" type="number" min="1" step="1"
                                                value="60" placeholder="Focus minutes">
                                        </p>
                                        <p class="control">
                                            <span class="button is-static">min</span>
                                        </p>
                                    </div>
                                </div>
                                <p class="help">How long to focus before a break</p>
                            </div>
                        </div>
                        <div class="column is-one-third">
                            <div class="field">
                                <label class="label">
                                    <span class="icon-text">
                                        <span class="icon">
                                            <i class="fas fa-wind" aria-hidden="true"></i>
                                        </span>
                                        <span>Micro Break Duration</span>
                                    </span>
                                </label>
                                <div class="control">
                                    <div class="field has-addons">
                                        <p class="control is-expanded">
                                            <input id="microBreakMinutes" class="input" type="number" min="1" step="1"
                                                value="5" placeholder="Micro break minutes">
                                        </p>
                                        <p class="control">
                                            <span class="button is-static">min</span>
                                        </p>
                                    </div>
                                </div>
                                <p class="help">Quick break duration</p>
                            </div>
                        </div>
                        <div class="column is-one-third">
                            <div class="field">
                                <label class="label">
                                    <span class="icon-text">
                                        <span class="icon">
                                            <i class="fas fa-leaf" aria-hidden="true"></i>
                                        </span>
                                        <span>Recharge Break Duration</span>
                                    </span>
                                </label>
                                <div class="control">
                                    <div class="field has-addons">
                                        <p class="control is-expanded">
                                            <input id="breakLengthMinutes" class="input" type="number" min="1" step="1"
                                                value="30" placeholder="Break minutes">
                                        </p>
                                        <p class="control">
                                            <span class="button is-static">min</span>
                                        </p>
                                    </div>
                                </div>
                                <p class="help">Longer break for recharging</p>
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
                        <button type="button" class="button is-medium is-danger" data-specter-phase="focus"
                            style="flex: 1; min-width: 100%; margin-bottom: 0.5rem;">
                            <span class="icon">
                                <i class="fas fa-hourglass-start" aria-hidden="true"></i>
                            </span>
                            <span>Start Focus Sprint</span>
                        </button>
                        <button type="button" class="button is-medium is-info" data-specter-phase="micro"
                            style="flex: 1; min-width: 100%; margin-bottom: 0.5rem;">
                            <span class="icon">
                                <i class="fas fa-mug-hot" aria-hidden="true"></i>
                            </span>
                            <span>Start Micro Break</span>
                        </button>
                        <button type="button" class="button is-medium is-warning" data-specter-phase="recharge"
                            style="flex: 1; min-width: 100%; margin-bottom: 0;">
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
                        <button type="button" class="button is-medium is-primary" data-specter-control="start"
                            style="flex: 1; min-width: calc(50% - 0.25rem); margin-right: 0.5rem; margin-bottom: 0.5rem;">
                            <span class="icon">
                                <i class="fas fa-play" aria-hidden="true"></i>
                            </span>
                            <span>Start</span>
                        </button>
                        <button type="button" class="button is-medium is-warning" data-specter-control="pause"
                            style="flex: 1; min-width: calc(50% - 0.25rem); margin-bottom: 0.5rem;">
                            <span class="icon">
                                <i class="fas fa-pause" aria-hidden="true"></i>
                            </span>
                            <span>Pause</span>
                        </button>
                        <button type="button" class="button is-medium is-success" data-specter-control="resume"
                            style="flex: 1; min-width: calc(50% - 0.25rem); margin-right: 0.5rem; margin-bottom: 0.5rem;">
                            <span class="icon">
                                <i class="fas fa-redo" aria-hidden="true"></i>
                            </span>
                            <span>Resume</span>
                        </button>
                        <button type="button" class="button is-medium is-info" data-specter-control="reset"
                            style="flex: 1; min-width: calc(50% - 0.25rem); margin-bottom: 0.5rem;">
                            <span class="icon">
                                <i class="fas fa-sync" aria-hidden="true"></i>
                            </span>
                            <span>Reset</span>
                        </button>
                        <button type="button" class="button is-medium is-danger" data-specter-control="stop"
                            style="flex: 1; min-width: 100%;">
                            <span class="icon">
                                <i class="fas fa-stop" aria-hidden="true"></i>
                            </span>
                            <span>Stop</span>
                        </button>
                    </div>
                </div>
                <div class="column is-full">
                    <h3 class="title is-6">
                        <span class="icon-text">
                            <span class="icon">
                                <i class="fas fa-hourglass" aria-hidden="true"></i>
                            </span>
                            <span>Live Timer</span>
                        </span>
                    </h3>
                    <div class="box"
                        style="background-color: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.1); text-align: center;">
                        <div style="margin-bottom: 12px;">
                            <p style="font-size: 0.85rem; color: rgba(255, 255, 255, 0.6); margin-bottom: 8px;">
                                <span id="livePhaseLabel">Focus Sprint</span> • <span id="livePhaseStatus">Waiting to
                                    start</span>
                            </p>
                        </div>
                        <p id="liveTimerDisplay"
                            style="font-size: 4rem; font-weight: 700; color: #ff9161; margin: 0; font-variant-numeric: tabular-nums; font-family: 'Monaco', 'Menlo', monospace;">
                            00:00</p>
                        <p style="font-size: 0.75rem; color: rgba(255, 255, 255, 0.5); margin-top: 8px;">
                            <span id="liveTimerState" style="color: rgba(255, 255, 255, 0.7);">Not Running</span>
                        </p>
                    </div>
                </div>
                <div class="column is-full">
                    <h3 class="title is-6">
                        <span class="icon-text">
                            <span class="icon">
                                <i class="fas fa-chart-line" aria-hidden="true"></i>
                            </span>
                            <span>Live Session Stats</span>
                        </span>
                    </h3>
                    <div class="box"
                        style="background-color: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.1);">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; text-align: center;">
                            <div>
                                <p style="font-size: 0.85rem; color: rgba(255, 255, 255, 0.6); margin-bottom: 8px;">
                                    <i class="fas fa-hourglass-end" style="margin-right: 6px;"></i>Sessions Completed
                                </p>
                                <p id="overlaySessionsCount"
                                    style="font-size: 2.5rem; font-weight: 700; color: #ff9161; margin: 0;">0</p>
                            </div>
                            <div>
                                <p style="font-size: 0.85rem; color: rgba(255, 255, 255, 0.6); margin-bottom: 8px;">
                                    <i class="fas fa-clock" style="margin-right: 6px;"></i>Total Focus Time
                                </p>
                                <p id="overlayTotalTime"
                                    style="font-size: 2.5rem; font-weight: 700; color: #6be9ff; margin: 0;">0h 0m</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card">
        <header class="card-header">
            <p class="card-header-title">
                Task List Management
            </p>
            <div class="buttons">
                <button type="button" class="button is-info is-small" id="copyTasklistCombinedBtn">
                    <span class="icon">
                        <i class="fas fa-copy" aria-hidden="true"></i>
                    </span>
                    <span>Copy Link (Combined)</span>
                </button>
                <button type="button" class="button is-info is-small" id="copyTasklistLinkBtn">
                    <span class="icon">
                        <i class="fas fa-copy" aria-hidden="true"></i>
                    </span>
                    <span>Copy Link (Streamer)</span>
                </button>
                <button type="button" class="button is-info is-small" id="copyTasklistUserLinkBtn">
                    <span class="icon">
                        <i class="fas fa-copy" aria-hidden="true"></i>
                    </span>
                    <span>Copy Link (Users)</span>
                </button>
            </div>
        </header>
        <div class="card-content">
            <div class="content">
                <p><strong>Display a Task List on Your Stream</strong></p>
                <p>Create and manage a beautiful task list overlay that shows your productivity goals to viewers. Tasks
                    can be toggled as complete by users watching your stream.</p>
            </div>
            <div class="columns is-multiline">
                <div class="column is-full">
                    <h3 class="title is-6">Add New Task</h3>
                    <div class="field is-grouped">
                        <div class="control is-expanded">
                            <input id="taskInputTitle" class="input" type="text" placeholder="Enter task title..."
                                maxlength="100">
                        </div>
                        <div class="control">
                            <div class="select">
                                <select id="taskInputPriority">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                        </div>
                        <div class="control">
                            <button type="button" class="button is-primary" id="addTaskBtn">
                                <span class="icon">
                                    <i class="fas fa-plus" aria-hidden="true"></i>
                                </span>
                                <span>Add Task</span>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="column is-full">
                    <h3 class="title is-6">Current Tasks</h3>
                    <div id="taskListDisplay"
                        style="max-height: 400px; overflow-y: auto; border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px;">
                        <div style="padding: 20px; text-align: center; color: rgba(255, 255, 255, 0.5);">
                            <p>No tasks yet. Add one to get started!</p>
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
<script src="https://cdn.socket.io/4.0.0/socket.io.min.js"></script>
<script>
    (function () {
        const apiKey = <?php echo json_encode($api_key); ?>;
        const currentUsername = <?php echo json_encode($_SESSION['username']); ?>;
        const dashboardDebug = false;
        const overlayLink = <?php echo json_encode($overlayLinkWithCode); ?>;
        const copyOverlayLinkBtn = document.getElementById('copyOverlayLinkBtn');
        const buttonsPhase = document.querySelectorAll('[data-specter-phase]');
        const buttonsControl = document.querySelectorAll('[data-specter-control]');
        const focusLengthInput = document.getElementById('focusLengthMinutes');
        const microBreakInput = document.getElementById('microBreakMinutes');
        const breakLengthInput = document.getElementById('breakLengthMinutes');
        const toastArea = document.getElementById('toastArea');
        const startBtn = document.querySelector('[data-specter-control="start"]');
        const pauseBtn = document.querySelector('[data-specter-control="pause"]');
        const resumeBtn = document.querySelector('[data-specter-control="resume"]');
        const stopBtn = document.querySelector('[data-specter-control="stop"]');
        const resetBtn = document.querySelector('[data-specter-control="reset"]');
        const overlaySessionsCountEl = document.getElementById('overlaySessionsCount');
        const overlayTotalTimeEl = document.getElementById('overlayTotalTime');
        const liveTimerDisplay = document.getElementById('liveTimerDisplay');
        const livePhaseLabel = document.getElementById('livePhaseLabel');
        const livePhaseStatus = document.getElementById('livePhaseStatus');
        const liveTimerState = document.getElementById('liveTimerState');
        let isRequesting = false;
        let timerState = 'stopped'; // stopped, running, paused
        let sessionsCompleted = 0;
        let totalTimeLogged = 0;
        // Task List Management
        const taskInputTitle = document.getElementById('taskInputTitle');
        const taskInputPriority = document.getElementById('taskInputPriority');
        const addTaskBtn = document.getElementById('addTaskBtn');
        const taskListDisplay = document.getElementById('taskListDisplay');
        const copyTasklistLinkBtn = document.getElementById('copyTasklistLinkBtn');
        const copyTasklistUserLinkBtn = document.getElementById('copyTasklistUserLinkBtn');
        const copyTasklistCombinedBtn = document.getElementById('copyTasklistCombinedBtn');
        let taskList = [];
        const tasklistLinkStreamer = `https://overlay.botofthespecter.com/working-or-study.php?code=${encodeURIComponent(apiKey)}&tasklist&streamer=true`;
        const tasklistLinkUsers = `https://overlay.botofthespecter.com/working-or-study.php?code=${encodeURIComponent(apiKey)}&tasklist&streamer=false`;
        const tasklistLinkCombined = `https://overlay.botofthespecter.com/working-or-study.php?code=${encodeURIComponent(apiKey)}&tasklist`;

        // PHP-injected initial data
        const initialSettings = <?php echo json_encode($initialSettings); ?>;
        const initialTasks = <?php echo json_encode($initialTasks); ?>;
        // Load settings from database (PHP-injected on page load)
        const loadSettingsFromDatabase = async () => {
            try {
                if (initialSettings) {
                    focusLengthInput.value = initialSettings.focus_minutes || 60;
                    microBreakInput.value = initialSettings.micro_break_minutes || 5;
                    breakLengthInput.value = initialSettings.recharge_break_minutes || 30;
                    console.log('[Timer] Settings loaded from page initialization:', initialSettings);
                }
            } catch (error) {
                console.error('[Timer] Error loading settings:', error);
            }
        };
        // Save settings to database via WebSocket (overlay will handle persistence)
        const saveSettingsToDatabase = async () => {
            try {
                const focus = focusLengthInput.value;
                const micro = microBreakInput.value;
                const recharge = breakLengthInput.value;
                if (socket && socket.connected) {
                    socket.emit('SPECTER_SETTINGS_UPDATE', {
                        code: apiKey,
                        focus_minutes: parseInt(focus),
                        micro_break_minutes: parseInt(micro),
                        recharge_break_minutes: parseInt(recharge)
                    });
                    console.log('[Timer] Settings saved and sent to overlay');
                    showToast('✓ Timer settings saved', 'success');
                } else {
                    console.warn('[Timer] WebSocket not connected, settings not sent to overlay');
                }
            } catch (error) {
                console.error('[Timer] Error saving settings:', error);
                showToast('⚠️ Error saving settings', 'danger');
            }
        };
        // Load tasks from database (PHP-injected on page load)
        const loadTasksFromDatabase = async () => {
            try {
                if (initialTasks && initialTasks.length > 0) {
                    taskList = initialTasks;
                    renderTaskList();
                    console.log('[Tasks] Loaded from page initialization:', taskList.length, 'tasks');
                } else {
                    console.log('[Tasks] No tasks loaded from page initialization');
                }
            } catch (error) {
                console.error('[Tasks] Error loading tasks:', error);
            }
        };
        // Save tasks to database via in-page operation + WebSocket sync
        const saveTasksToDatabase = async () => {
            try {
                // Create a hidden form and submit to save tasks
                const formData = new FormData();
                formData.append('action', 'save_tasks');
                formData.append('tasks', JSON.stringify(taskList));

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();
                if (result.success) {
                    console.log('[Tasks] Saved to dashboard database');
                }
            } catch (error) {
                console.error('[Tasks] Error saving tasks:', error);
            }
        };
        const renderTaskList = () => {
            if (!taskList || taskList.length === 0) {
                taskListDisplay.innerHTML = `
                    <div style="padding: 20px; text-align: center; color: rgba(255, 255, 255, 0.5);">
                        <p>No tasks yet. Add one to get started!</p>
                    </div>
                `;
                return;
            }
            taskListDisplay.innerHTML = taskList.map((task, index) => `
                <div style="padding: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); display: flex; justify-content: space-between; align-items: center; transition: background 0.2s ease;" class="task-dashboard-item">
                    <div style="flex: 1;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div class="task-checkbox-custom ${task.completed ? 'checked' : ''}" data-task-index="${index}" style="flex-shrink: 0; width: 20px; height: 20px; min-width: 20px; border: 2px solid ${task.completed ? '#2ecc71' : 'rgba(255, 255, 255, 0.3)'}; border-radius: 4px; background: ${task.completed ? '#2ecc71' : 'transparent'}; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; color: #ffffff; text-shadow: ${task.completed ? '0 1px 2px rgba(0, 0, 0, 0.3)' : 'none'};">${task.completed ? '✓' : ''}</div>
                            <span style="color: #f8fbff; ${task.completed ? 'text-decoration: line-through; opacity: 0.6;' : ''}">${escapeHtml(task.title)}</span>
                            <span style="padding: 2px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; background: ${getPriorityColor(task.priority, true)}; color: ${getPriorityColor(task.priority)};">${task.priority}</span>
                        </div>
                    </div>
                    <button type="button" class="button is-small is-danger is-light" data-task-index="${index}" style="margin-left: 8px;">
                        <span class="icon is-small">
                            <i class="fas fa-trash" aria-hidden="true"></i>
                        </span>
                    </button>
                </div>
            `).join('');
            // Add event listeners
            taskListDisplay.querySelectorAll('.task-checkbox-custom').forEach(checkbox => {
                checkbox.addEventListener('click', (e) => {
                    const index = parseInt(e.target.dataset.taskIndex);
                    taskList[index].completed = !taskList[index].completed;
                    renderTaskList();
                    emitTaskListUpdate();
                });
                checkbox.addEventListener('mouseenter', (e) => {
                    if (!taskList[parseInt(e.target.dataset.taskIndex)].completed) {
                        e.target.style.borderColor = 'var(--accent-color)';
                        e.target.style.background = 'rgba(255, 255, 255, 0.05)';
                    }
                });
                checkbox.addEventListener('mouseleave', (e) => {
                    if (!taskList[parseInt(e.target.dataset.taskIndex)].completed) {
                        e.target.style.borderColor = 'rgba(255, 255, 255, 0.3)';
                        e.target.style.background = 'transparent';
                    }
                });
            });
            taskListDisplay.querySelectorAll('.button.is-danger').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const index = parseInt(e.currentTarget.dataset.taskIndex);
                    taskList.splice(index, 1);
                    renderTaskList();
                    emitTaskListUpdate();
                });
            });
            // Add hover effect
            taskListDisplay.querySelectorAll('.task-dashboard-item').forEach(item => {
                item.addEventListener('mouseenter', () => {
                    item.style.background = 'rgba(255, 255, 255, 0.04)';
                });
                item.addEventListener('mouseleave', () => {
                    item.style.background = 'transparent';
                });
            });
        };
        const getPriorityColor = (priority, background = false) => {
            const colors = {
                high: { bg: 'rgba(255, 145, 97, 0.2)', text: '#ff9161' },
                medium: { bg: 'rgba(107, 233, 255, 0.2)', text: '#6be9ff' },
                low: { bg: 'rgba(180, 131, 255, 0.2)', text: '#b483ff' }
            };
            return (colors[priority.toLowerCase()] || colors.medium)[background ? 'bg' : 'text'];
        };
        const escapeHtml = (text) => {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        };
        const emitTaskListUpdate = () => {
            if (socket && socket.connected) {
                console.log('[Dashboard] Emitting task list via WebSocket:', taskList.length, 'tasks');
                socket.emit('SPECTER_TASKLIST', {
                    code: apiKey,
                    tasks: taskList,
                    streamerView: false,
                    timestamp: Date.now(),
                    channel: 'Overlay'  // Ensure task list overlay receives this on Overlay channel
                });
            } else {
                console.warn('[Dashboard] WebSocket not connected, cannot emit task list');
            }
            // Save to database
            saveTasksToDatabase();
        };
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

        const formatTime = (seconds) => {
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        };

        const updateLiveTimer = (timerData) => {
            if (!timerData) return;
            liveTimerDisplay.textContent = formatTime(timerData.remainingSeconds || 0);
            livePhaseLabel.textContent = timerData.phaseLabel || 'Unknown';
            livePhaseStatus.textContent = timerData.phaseStatus || 'Waiting';

            // Update timer color based on phase
            if (timerData.phaseColor) {
                liveTimerDisplay.style.color = timerData.phaseColor;
            }

            // Update timer state display
            if (timerData.timerRunning) {
                liveTimerState.textContent = 'Running';
                liveTimerState.style.color = '#6be9ff';
            } else if (timerData.timerPaused) {
                liveTimerState.textContent = 'Paused';
                liveTimerState.style.color = '#ff9161';
            } else {
                liveTimerState.textContent = 'Not Running';
                liveTimerState.style.color = 'rgba(255, 255, 255, 0.7)';
            }
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
        };
        const notifyServer = async (payload, toastMessage = '', toastType = 'success') => {
            if (isRequesting) return;
            setButtonsLoading(true);
            try {
                if (!socket || !socket.connected || !socketReady) {
                    console.error('[Timer Dashboard] Socket not connected');
                    console.log('[Timer Dashboard] Socket state:', {
                        exists: !!socket,
                        connected: socket?.connected,
                        ready: socketReady
                    });
                    showToast('⚠️ Not connected to timer server', 'danger');
                    setButtonsLoading(false);
                    return;
                }
                console.log('[Timer Dashboard] notifyServer called with payload:', payload);
                console.log('[Timer Dashboard] gatherDurations result:', gatherDurations());
                const fullPayload = {
                    code: apiKey,
                    ...payload
                };
                console.log('[Timer Dashboard] Full payload to emit:', fullPayload);
                console.log('[Timer Dashboard] Event name:', payload.specter_event);
                // Send via WebSocket instead of HTTP
                socket.emit(payload.specter_event, fullPayload);
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
            micro_minutes: safeNumberValue(microBreakInput, 5),
            break_minutes: safeNumberValue(breakLengthInput, 30)
        });
        // WebSocket connection for real-time sync
        const socketUrl = 'wss://websocket.botofthespecter.com';
        let socket;
        let socketReady = false;
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
        if (typeof localStorage !== 'undefined') {
            const storedDebug = localStorage.getItem('debug');
            if (storedDebug && /socket\.io|engine\.io/.test(storedDebug)) {
                localStorage.removeItem('debug');
            }
        }
        const connect = () => {
            console.log(`[Timer Dashboard] Connecting to WebSocket: ${socketUrl}`);
            socket = io(socketUrl, { reconnection: false });
            socketReady = false;
            socket.on('connect', () => {
                attempts = 0;
                console.log('[Timer Dashboard] ✓ Connected to WebSocket');
                console.log('[Timer Dashboard] Registering as Dashboard');
                socket.emit('REGISTER', { code: apiKey, channel: 'Dashboard', name: 'Working Study Timer Dashboard' });
                // Request stats from overlay on connect
                socket.emit('SPECTER_STATS_REQUEST', { code: apiKey });
            });
            socket.on('disconnect', (reason) => {
                console.warn(`[Timer Dashboard] ✗ Disconnected: ${reason}`);
                socketReady = false;
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
                // Update the UI display elements
                overlaySessionsCountEl.textContent = sessionsCompleted;
                overlayTotalTimeEl.textContent = formatTotalTime(totalTimeLogged);
            });
            socket.on('SPECTER_TIMER_UPDATE', payload => {
                console.log('[Timer Dashboard] Received timer update:', payload);
                updateLiveTimer(payload);
            });
            socket.on('SPECTER_TASKLIST_UPDATE', payload => {
                console.log('[Timer Dashboard] Received task list update:', payload);
                if (payload.tasks) {
                    taskList = payload.tasks;
                    renderTaskList();
                }
            });
            socket.on('SUCCESS', payload => {
                if (payload && typeof payload.message === 'string' && payload.message.toLowerCase().includes('registration')) {
                    socketReady = true;
                    console.log('[Timer Dashboard] Registration acknowledged by server');
                }
            });
            if (dashboardDebug) {
                socket.onAny((event, ...args) => {
                    console.debug(`[Timer Dashboard] WebSocket event: ${event}`, args);
                });
            }
        };
        connect();
        // Initialize button states
        updateButtonStates();
        // Copy overlay link button
        copyOverlayLinkBtn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(overlayLink);
                showToast('✓ Timer overlay link copied to clipboard!', 'success');
                // Change button appearance temporarily
                const originalHTML = copyOverlayLinkBtn.innerHTML;
                copyOverlayLinkBtn.innerHTML = '<span class="icon"><i class="fas fa-check" aria-hidden="true"></i></span><span>Copied!</span>';
                setTimeout(() => {
                    copyOverlayLinkBtn.innerHTML = originalHTML;
                }, 2000);
            } catch (err) {
                console.error('Failed to copy:', err);
                showToast('⚠️ Failed to copy link to clipboard', 'danger');
            }
        });
        // Task list copy buttons
        copyTasklistLinkBtn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(tasklistLinkStreamer);
                showToast('✓ Streamer task list link copied!', 'success');
                const originalHTML = copyTasklistLinkBtn.innerHTML;
                copyTasklistLinkBtn.innerHTML = '<span class="icon"><i class="fas fa-check" aria-hidden="true"></i></span><span>Copied!</span>';
                setTimeout(() => {
                    copyTasklistLinkBtn.innerHTML = originalHTML;
                }, 2000);
            } catch (err) {
                console.error('Failed to copy:', err);
                showToast('⚠️ Failed to copy link', 'danger');
            }
        });
        copyTasklistUserLinkBtn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(tasklistLinkUsers);
                showToast('✓ Users task list link copied!', 'success');
                const originalHTML = copyTasklistUserLinkBtn.innerHTML;
                copyTasklistUserLinkBtn.innerHTML = '<span class="icon"><i class="fas fa-check" aria-hidden="true"></i></span><span>Copied!</span>';
                setTimeout(() => {
                    copyTasklistUserLinkBtn.innerHTML = originalHTML;
                }, 2000);
            } catch (err) {
                console.error('Failed to copy:', err);
                showToast('⚠️ Failed to copy link', 'danger');
            }
        });
        // Combined task list link (works for both streamer and users)
        copyTasklistCombinedBtn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(tasklistLinkCombined);
                showToast('✓ Combined task list link copied!', 'success');
                const originalHTML = copyTasklistCombinedBtn.innerHTML;
                copyTasklistCombinedBtn.innerHTML = '<span class="icon"><i class="fas fa-check" aria-hidden="true"></i></span><span>Copied!</span>';
                setTimeout(() => {
                    copyTasklistCombinedBtn.innerHTML = originalHTML;
                }, 2000);
            } catch (err) {
                console.error('Failed to copy:', err);
                showToast('⚠️ Failed to copy link', 'danger');
            }
        });
        // Add task button
        addTaskBtn.addEventListener('click', () => {
            const title = taskInputTitle.value.trim();
            const priority = taskInputPriority.value;
            if (!title) {
                showToast('⚠️ Please enter a task title', 'warning');
                taskInputTitle.focus();
                return;
            }
            taskList.push({
                id: Date.now(),
                title,
                priority,
                completed: false,
                username: currentUsername,
                createdAt: new Date().toISOString()
            });

            taskInputTitle.value = '';
            taskInputPriority.value = 'medium';
            renderTaskList();
            emitTaskListUpdate();
            showToast(`✓ Task added: "${title}"`, 'success');
        });
        // Enter key to add task
        taskInputTitle.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                addTaskBtn.click();
            }
        });
        // Initialize by loading from database
        loadSettingsFromDatabase();
        loadTasksFromDatabase();
        buttonsPhase.forEach(button => {
            button.addEventListener('click', async () => {
                const phase = button.getAttribute('data-specter-phase');
                const payload = { phase, auto_start: 1 };
                if (phase === 'focus') {
                    payload.duration_minutes = safeNumberValue(focusLengthInput, 60);
                } else if (phase === 'micro') {
                    payload.duration_minutes = safeNumberValue(microBreakInput, 5);
                } else if (phase === 'recharge') {
                    payload.duration_minutes = safeNumberValue(breakLengthInput, 30);
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
                console.log(`[Timer Dashboard] Control button clicked: ${action}`);
                const toastMessage = controlMessages[action] || `Timer ${action}`;
                await notifyServer(
                    { specter_event: 'SPECTER_TIMER_COMMAND', action, ...gatherDurations() },
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
            } else {
                saveSettingsToDatabase();
            }
        });
        microBreakInput.addEventListener('change', () => {
            const val = Number(microBreakInput.value);
            if (!Number.isFinite(val) || val < 1) {
                microBreakInput.value = 5;
                showToast('⚠️ Micro break duration must be at least 1 minute', 'danger');
            } else {
                saveSettingsToDatabase();
            }
        });
        breakLengthInput.addEventListener('change', () => {
            const val = Number(breakLengthInput.value);
            if (!Number.isFinite(val) || val < 1) {
                breakLengthInput.value = 30;
                showToast('⚠️ Break duration must be at least 1 minute', 'danger');
            } else {
                saveSettingsToDatabase();
            }
        });
    })();
</script>
<?php
$scripts = ob_get_clean();

include 'layout.php';
?>