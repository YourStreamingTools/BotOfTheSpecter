<?php
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
$allowed_overlay_themes = ['dark', 'peachy', 'ocean', 'forest', 'midnight', 'pride'];
$overlay_theme = 'dark';
$allowed_list_view_modes = ['split', 'unified'];
$list_view_mode = 'split';
if ($has_timer_query && !$has_tasklist_query) {
    $overlay_mode_class = 'study-overlay-page--timer-only';
} elseif ($has_tasklist_query && !$has_timer_query) {
    $show_timer_panel = false;
    $overlay_mode_class = 'study-overlay-page--tasks-only';
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
        $themeStmt = @$user_db->query("SELECT theme FROM working_study_overlay_settings LIMIT 1");
        if ($themeStmt && ($themeRow = $themeStmt->fetch_assoc())) {
            $candidate = $themeRow['theme'] ?? 'dark';
            if (in_array($candidate, $allowed_overlay_themes, true)) {
                $overlay_theme = $candidate;
            }
        }
        $listViewStmt = @$user_db->query("SELECT list_view_mode FROM working_study_overlay_settings LIMIT 1");
        if ($listViewStmt && ($listViewRow = $listViewStmt->fetch_assoc())) {
            $listCandidate = $listViewRow['list_view_mode'] ?? 'split';
            if (in_array($listCandidate, $allowed_list_view_modes, true)) {
                $list_view_mode = $listCandidate;
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
        $stmt = $user_db->prepare("SELECT focus_minutes, micro_break_minutes, recharge_break_minutes, cycle_count, show_cycle_badge, theme, list_view_mode FROM working_study_overlay_settings LIMIT 1");
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
        // Whitelist of supported overlay themes; anything else falls back to dark.
        $allowedThemes = ['dark', 'peachy', 'ocean', 'forest', 'midnight', 'pride'];
        // Whitelist of supported list view modes; anything else falls back to split.
        $allowedListViewModes = ['split', 'unified'];
        if ($settings) {
            $theme = in_array($settings['theme'] ?? 'dark', $allowedThemes, true) ? $settings['theme'] : 'dark';
            $listViewMode = in_array($settings['list_view_mode'] ?? 'split', $allowedListViewModes, true) ? $settings['list_view_mode'] : 'split';
            echo json_encode([
                'success' => true,
                'data' => [
                    'focus_minutes' => (int) $settings['focus_minutes'],
                    'micro_break_minutes' => (int) $settings['micro_break_minutes'],
                    'recharge_break_minutes' => (int) $settings['recharge_break_minutes'],
                    'cycle_count' => (int) $settings['cycle_count'],
                    'show_cycle_badge' => (int) $settings['show_cycle_badge'],
                    'theme' => $theme,
                    'list_view_mode' => $listViewMode
                ]
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'data' => [
                    'focus_minutes' => 60,
                    'micro_break_minutes' => 5,
                    'recharge_break_minutes' => 30,
                    'cycle_count' => 4,
                    'show_cycle_badge' => 0,
                    'theme' => 'dark',
                    'list_view_mode' => 'split'
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
            $s = $user_db->prepare("SELECT id, title, description, category, status, reward_points, project, backlog_position, user_id, user_name FROM streamer_tasks WHERE status != 'hidden' ORDER BY created_at DESC");
            if ($s && $s->execute()) {
                $streamer_tasks_arr = $s->get_result()->fetch_all(MYSQLI_ASSOC);
                $s->close();
            }
        }
        if ($ut_exists) {
            $u = $user_db->prepare("SELECT id, user_id, user_name, title, description, status, reward_points, completed_at, project, backlog_position FROM user_tasks WHERE status != 'rejected' ORDER BY created_at DESC");
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
    <link rel="stylesheet" href="index.css?v=<?php echo filemtime(__DIR__ . '/index.css'); ?>">
</head>
<body class="study-overlay-page">
    <?php if ($error_html): ?>
        <div class="study-overlay-page-error-screen">
            <h1>Overlay unavailable</h1>
            <p id="overlayErrorMessage"><?php echo $error_html; ?></p>
        </div>
    <?php else: ?>
        <?php
            $is_unified = ($list_view_mode === 'unified');
            $show_unified_panel = $has_tasklist_query && $is_unified;
            $render_streamer_split = $show_new_streamer_panel && !$is_unified;
            $render_viewer_split   = $show_new_viewer_panel && !$is_unified;
        ?>
        <div class="study-overlay-page-root<?php echo $overlay_mode_class ? ' ' . htmlspecialchars($overlay_mode_class) : ''; ?>"
            id="overlayRoot" data-theme="<?php echo htmlspecialchars($overlay_theme); ?>"
            data-list-view="<?php echo htmlspecialchars($list_view_mode); ?>">
            <div id="connectionStatus" class="study-overlay-page-connection-status" data-state="connecting">Connecting…</div>
            <section class="study-overlay-page-timer-card" data-visible="<?php echo $show_timer_panel ? 'true' : 'false'; ?>">
                <div class="study-overlay-page-timer-ring">
                    <svg viewBox="0 0 220 220">
                        <circle class="study-overlay-page-ring-bg" cx="110" cy="110" r="98"></circle>
                        <circle class="study-overlay-page-ring-progress" id="timerRingProgress" cx="110" cy="110" r="98">
                        </circle>
                    </svg>
                    <div class="study-overlay-page-timer-inner">
                        <div class="study-overlay-page-phase-row">
                            <div id="phaseLabel" class="study-overlay-page-phase-label">Focus Sprint</div>
                            <div id="cycleBadge" class="study-overlay-page-cycle-badge" data-visible="false"></div>
                        </div>
                        <div id="timerDisplay" class="study-overlay-page-timer-display">00:00</div>
                        <div id="statusText" class="study-overlay-page-timer-status">Waiting</div>
                    </div>
                </div>
                <div class="study-overlay-page-stats-row">
                    <div>
                        <div class="study-overlay-page-stat-label">Focus</div>
                        <div class="study-overlay-page-stat-value" id="focusSessionsCompleted">0</div>
                    </div>
                    <div>
                        <div class="study-overlay-page-stat-label">Micro</div>
                        <div class="study-overlay-page-stat-value" id="microSessionsCompleted">0</div>
                    </div>
                    <div>
                        <div class="study-overlay-page-stat-label">Recharge</div>
                        <div class="study-overlay-page-stat-value" id="rechargeSessionsCompleted">0</div>
                    </div>
                    <div>
                        <div class="study-overlay-page-stat-label">Total Sessions</div>
                        <div class="study-overlay-page-stat-value" id="sessionsCompleted">0</div>
                    </div>
                    <div>
                        <div class="study-overlay-page-stat-label">Total time</div>
                        <div class="study-overlay-page-stat-value" id="totalTimeLogged">0m</div>
                    </div>
                </div>
            </section>
            <!-- New task system: Streamer Tasks panel -->
            <!-- Shown when ?tasklist is present (filtered by ?tasklist&streamer=true to show only this panel) -->
            <section class="study-overlay-page-task-sys-card study-overlay-page-task-sys-card--streamer"
                     data-visible="<?php echo $render_streamer_split ? 'true' : 'false'; ?>"
                     <?php if (!$render_streamer_split) echo 'style="display:none"'; ?>>
                <div class="study-overlay-page-task-sys-card-header">
                    <span class="study-overlay-page-task-sys-card-dot" style="background:#48c78e"></span>
                    Streamer Tasks
                </div>
                <ul class="study-overlay-page-task-sys-card-list" id="newStreamerTaskList"></ul>
            </section>
            <!-- New task system: Viewer Tasks panel -->
            <!-- Shown when ?tasklist is present (hidden when ?tasklist&streamer=true) -->
            <section class="study-overlay-page-task-sys-card study-overlay-page-task-sys-card--viewer"
                     data-visible="<?php echo $render_viewer_split ? 'true' : 'false'; ?>"
                     <?php if (!$render_viewer_split) echo 'style="display:none"'; ?>>
                <div class="study-overlay-page-task-sys-card-header">
                    <span class="study-overlay-page-task-sys-card-dot" style="background:#3e8ed0"></span>
                    Viewer Tasks
                </div>
                <ul class="study-overlay-page-task-sys-card-list" id="newViewerTaskList"></ul>
            </section>
            <section class="study-overlay-page-task-sys-card study-overlay-page-task-sys-card--unified"
                     data-visible="<?php echo $show_unified_panel ? 'true' : 'false'; ?>"
                     <?php if (!$show_unified_panel) echo 'style="display:none"'; ?>>
                <div class="study-overlay-page-task-sys-card-header">
                    <span class="study-overlay-page-task-sys-card-dot" style="background:#9b8cff"></span>
                    Tasks
                </div>
                <ul class="study-overlay-page-task-sys-card-list" id="newUnifiedTaskList"></ul>
            </section>
            <div id="taskRewardPopups"></div>
        </div>
    <?php endif; ?>
    <script>
        const overlayApiKey = <?php echo json_encode($api_key ?? null); ?>;
        const overlayUserName = <?php echo json_encode($username ?? 'Specter User'); ?>;
        const overlayErrorMessage = <?php echo json_encode($error_html ?? null); ?>;
        const hasTimerQuery = <?php echo $has_timer_query ? 'true' : 'false'; ?>;
        const hasTasklistQuery = <?php echo $has_tasklist_query ? 'true' : 'false'; ?>;
        const streamerFilterParam = <?php echo json_encode($streamer_filter_param); ?>;
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
            const cycleBadge = document.getElementById('cycleBadge');
            const timerDisplay = document.getElementById('timerDisplay');
            const statusText = document.getElementById('statusText');
            const focusSessionsCompletedEl = document.getElementById('focusSessionsCompleted');
            const microSessionsCompletedEl = document.getElementById('microSessionsCompleted');
            const rechargeSessionsCompletedEl = document.getElementById('rechargeSessionsCompleted');
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
                focus: { label: 'Focus Sprint', status: 'Flow mode on', accent: '#ff9161', cssVar: '--focus-color' },
                micro: { label: 'Micro Break', status: 'Reignite energy', accent: '#48c78e', cssVar: '--micro-color' },
                recharge: { label: 'Recharge Stretch', status: 'Stretch & hydrate', accent: '#7c5cff', cssVar: '--recharge-color' }
            };
            const allowedThemes = ['dark', 'peachy', 'ocean', 'forest', 'midnight', 'pride'];
            const applyOverlayTheme = theme => {
                if (theme === undefined || theme === null) {
                    return;
                }
                const next = allowedThemes.includes(String(theme)) ? String(theme) : 'dark';
                if (overlayRoot.dataset.theme !== next) {
                    overlayRoot.dataset.theme = next;
                    // Re-render so the timer ring/accent picks up the new theme's
                    // per-phase colour immediately.
                    updateDisplay();
                }
            };
            const allowedViewModes = ['split', 'unified'];
            const getListViewMode = () => {
                const current = String(overlayRoot.dataset.listView || 'split');
                return allowedViewModes.includes(current) ? current : 'split';
            };
            const applyListViewMode = mode => {
                if (mode === undefined || mode === null) {
                    return;
                }
                const next = allowedViewModes.includes(String(mode)) ? String(mode) : 'split';
                if (overlayRoot.dataset.listView !== next) {
                    overlayRoot.dataset.listView = next;
                }
                if (typeof syncTaskPanelVisibility === 'function') {
                    syncTaskPanelVisibility();
                }
                if (typeof renderTaskLists === 'function') {
                    renderTaskLists();
                }
            };
            const getPhaseAccent = phaseKey => {
                const phase = phases[phaseKey];
                if (!phase) {
                    return '#ff9161';
                }
                try {
                    const value = getComputedStyle(overlayRoot).getPropertyValue(phase.cssVar).trim();
                    if (value) {
                        return value;
                    }
                } catch (error) {
                    /* getComputedStyle unavailable — fall through to the static accent */
                }
                return phase.accent;
            };
            const timerState = {
                currentPhase: 'focus',
                remainingSeconds: defaultDurations.focus,
                totalDuration: defaultDurations.focus,
                timerRunning: false,
                timerPaused: false,
                interruptedFocus: null,
                countdownId: null,
                sessionsCompleted: 0,
                totalTimeLogged: 0,
                phaseCounts: { focus: 0, micro: 0, recharge: 0 },
                legacySessionOffset: 0,
                currentCycle: 1,
                durations: { ...defaultDurations }
            };
            const cycleConfig = {
                count: 4,
                showBadge: false,
                autoCycleEnabled: false
            };
            const clampCurrentCycle = () => {
                const max = Math.max(1, Math.round(Number(cycleConfig.count) || 1));
                if (!Number.isFinite(timerState.currentCycle) || timerState.currentCycle < 1) {
                    timerState.currentCycle = 1;
                } else if (timerState.currentCycle > max) {
                    timerState.currentCycle = max;
                } else {
                    timerState.currentCycle = Math.round(timerState.currentCycle);
                }
            };
            const updateCycleBadge = () => {
                if (!cycleBadge) {
                    return;
                }
                if (!cycleConfig.showBadge) {
                    cycleBadge.dataset.visible = 'false';
                    cycleBadge.textContent = '';
                    return;
                }
                clampCurrentCycle();
                const max = Math.max(1, Math.round(Number(cycleConfig.count) || 1));
                cycleBadge.textContent = `${timerState.currentCycle} / ${max}`;
                cycleBadge.dataset.visible = 'true';
            };
            const applyCycleConfigFromPayload = payload => {
                if (!payload) {
                    return;
                }
                if (payload.cycle_count !== undefined && payload.cycle_count !== null) {
                    const count = Number(payload.cycle_count);
                    if (Number.isFinite(count) && count >= 1) {
                        cycleConfig.count = Math.round(count);
                    }
                }
                if (payload.show_cycle_badge !== undefined && payload.show_cycle_badge !== null) {
                    cycleConfig.showBadge = Number(payload.show_cycle_badge) === 1 || payload.show_cycle_badge === true;
                }
                if (payload.auto_cycle_enabled !== undefined && payload.auto_cycle_enabled !== null) {
                    cycleConfig.autoCycleEnabled = Number(payload.auto_cycle_enabled) === 1 || payload.auto_cycle_enabled === true;
                }
                if (payload.reset_cycle !== undefined && payload.reset_cycle !== null
                    && (Number(payload.reset_cycle) === 1 || payload.reset_cycle === true)) {
                    timerState.currentCycle = 1;
                    saveTimerState();
                }
                clampCurrentCycle();
                updateCycleBadge();
            };
            const timerStateStorageKey = `specter:working-study:timer:${overlayApiKey}`;
            const timerStatsStorageKey = `specter:working-study:stats:${overlayApiKey}`;
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
            const normalizePhaseCounts = counts => {
                const source = counts && typeof counts === 'object' ? counts : {};
                return {
                    focus: Number.isFinite(Number(source.focus)) && Number(source.focus) >= 0 ? Math.round(Number(source.focus)) : 0,
                    micro: Number.isFinite(Number(source.micro)) && Number(source.micro) >= 0 ? Math.round(Number(source.micro)) : 0,
                    recharge: Number.isFinite(Number(source.recharge)) && Number(source.recharge) >= 0 ? Math.round(Number(source.recharge)) : 0
                };
            };
            const getPhaseSessionTotal = () => {
                return (timerState.phaseCounts.focus || 0) + (timerState.phaseCounts.micro || 0) + (timerState.phaseCounts.recharge || 0);
            };
            const getTotalSessions = () => {
                return (timerState.legacySessionOffset || 0) + getPhaseSessionTotal();
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
                overlayRoot.style.setProperty('--accent-color', getPhaseAccent(phase));
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
                if (focusSessionsCompletedEl) focusSessionsCompletedEl.textContent = timerState.phaseCounts.focus || 0;
                if (microSessionsCompletedEl) microSessionsCompletedEl.textContent = timerState.phaseCounts.micro || 0;
                if (rechargeSessionsCompletedEl) rechargeSessionsCompletedEl.textContent = timerState.phaseCounts.recharge || 0;
                sessionsCompletedEl.textContent = getTotalSessions();
                totalTimeLoggedEl.textContent = formatTotalTime(timerState.totalTimeLogged);
            };
            const saveSessionStats = () => {
                try {
                    if (typeof window === 'undefined' || !window.localStorage) {
                        return;
                    }
                    window.localStorage.setItem(timerStatsStorageKey, JSON.stringify({
                        sessionsCompleted: getTotalSessions(),
                        totalTimeLogged: timerState.totalTimeLogged,
                        phaseCounts: timerState.phaseCounts,
                        legacySessionOffset: timerState.legacySessionOffset,
                        lastUpdatedAt: Date.now()
                    }));
                } catch (error) {
                    console.warn('[Overlay] Unable to persist timer stats:', error);
                }
            };
            const restoreSavedSessionStats = () => {
                try {
                    if (typeof window === 'undefined' || !window.localStorage) {
                        return;
                    }
                    const raw = window.localStorage.getItem(timerStatsStorageKey);
                    if (!raw) {
                        return;
                    }
                    const savedStats = JSON.parse(raw);
                    if (!savedStats || typeof savedStats !== 'object') {
                        return;
                    }
                    timerState.phaseCounts = normalizePhaseCounts(savedStats.phaseCounts);
                    if (Number.isFinite(savedStats.legacySessionOffset) && savedStats.legacySessionOffset >= 0) {
                        timerState.legacySessionOffset = Math.round(savedStats.legacySessionOffset);
                    } else {
                        timerState.legacySessionOffset = 0;
                    }
                    if (Number.isFinite(savedStats.sessionsCompleted) && savedStats.sessionsCompleted >= 0 && getPhaseSessionTotal() === 0) {
                        timerState.legacySessionOffset = Math.round(savedStats.sessionsCompleted);
                    }
                    if (Number.isFinite(savedStats.totalTimeLogged) && savedStats.totalTimeLogged >= 0) {
                        timerState.totalTimeLogged = Math.round(savedStats.totalTimeLogged);
                    }
                    timerState.sessionsCompleted = getTotalSessions();
                } catch (error) {
                    console.warn('[Overlay] Unable to restore timer stats:', error);
                }
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
                        interruptedFocus: timerState.interruptedFocus,
                        sessionsCompleted: getTotalSessions(),
                        totalTimeLogged: timerState.totalTimeLogged,
                        phaseCounts: timerState.phaseCounts,
                        legacySessionOffset: timerState.legacySessionOffset,
                        currentCycle: timerState.currentCycle,
                        durations: timerState.durations,
                        lastUpdatedAt: Date.now()
                    }));
                    saveSessionStats();
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
                        phaseColor: getPhaseAccent(timerState.currentPhase)
                    });
                }
            };
            const emitSessionStats = () => {
                if (socket && socket.connected) {
                    socket.emit('SPECTER_SESSION_STATS', {
                        code: overlayApiKey,
                        sessionsCompleted: getTotalSessions(),
                        totalSessions: getTotalSessions(),
                        totalTimeLogged: timerState.totalTimeLogged,
                        phaseCounts: timerState.phaseCounts
                    });
                }
            };
            const recordCompletedPhase = (phaseKey, durationSeconds = 0) => {
                if (phaseKey && Object.prototype.hasOwnProperty.call(timerState.phaseCounts, phaseKey)) {
                    timerState.phaseCounts[phaseKey] += 1;
                }
                if (phaseKey === 'focus') {
                    const max = Math.max(1, Math.round(Number(cycleConfig.count) || 1));
                    if (timerState.currentCycle < max) {
                        timerState.currentCycle += 1;
                    }
                    updateCycleBadge();
                }
                timerState.sessionsCompleted = getTotalSessions();
                timerState.totalTimeLogged += Math.max(0, Math.round(Number(durationSeconds) || 0));
                saveSessionStats();
                updateStatsDisplay();
                emitSessionStats();
            };
            const clearInterruptedFocus = () => {
                timerState.interruptedFocus = null;
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
                if (
                    timerState.currentPhase === 'micro' &&
                    timerState.interruptedFocus &&
                    Number.isFinite(timerState.interruptedFocus.remainingSeconds) &&
                    timerState.interruptedFocus.remainingSeconds > 0
                ) {
                    const finishedPhase = timerState.currentPhase;
                    const finishedDuration = timerState.totalDuration;
                    const interruptedFocus = timerState.interruptedFocus;
                    clearInterruptedFocus();
                    recordCompletedPhase(finishedPhase, finishedDuration);
                    timerState.currentPhase = 'focus';
                    timerState.totalDuration = interruptedFocus.totalDuration;
                    timerState.remainingSeconds = interruptedFocus.remainingSeconds;
                    timerState.timerRunning = false;
                    timerState.timerPaused = false;
                    updateDisplay();
                    saveTimerState();
                    emitTimerUpdate();
                    startCountdown();
                    return;
                }
                const finishedPhase = timerState.currentPhase;
                timerState.timerRunning = false;
                timerState.timerPaused = false;
                timerState.remainingSeconds = 0;
                clearInterruptedFocus();
                recordCompletedPhase(finishedPhase, timerState.totalDuration);
                
                if (cycleConfig.autoCycleEnabled) {
                    if (finishedPhase === 'focus') {
                        const max = Math.max(1, Math.round(Number(cycleConfig.count) || 1));
                        if (timerState.phaseCounts.focus % max === 0) {
                            setPhase('recharge', { autoStart: true });
                        } else {
                            setPhase('micro', { autoStart: true });
                        }
                        return;
                    } else if (finishedPhase === 'micro') {
                        setPhase('focus', { autoStart: true });
                        return;
                    }
                }

                clearSavedTimerState();
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
                clearInterruptedFocus();
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
                clearInterruptedFocus();
                saveTimerState();
                emitTimerState('stopped');
                updateDisplay();
                emitTimerUpdate();
            };
            const resetAllState = payload => {
                clearInterval(timerState.countdownId);
                timerState.countdownId = null;
                timerState.timerRunning = false;
                timerState.timerPaused = false;
                clearInterruptedFocus();
                const focusDefault = minutesToSeconds(payload?.focus_minutes) || defaultDurations.focus;
                const microDefault = minutesToSeconds(payload?.micro_minutes ?? payload?.micro_break_minutes) || defaultDurations.micro;
                const rechargeDefault = minutesToSeconds(payload?.break_minutes ?? payload?.recharge_break_minutes) || defaultDurations.recharge;
                timerState.durations.focus = focusDefault;
                timerState.durations.micro = microDefault;
                timerState.durations.recharge = rechargeDefault;
                timerState.currentPhase = 'focus';
                timerState.totalDuration = focusDefault;
                timerState.remainingSeconds = focusDefault;
                timerState.phaseCounts = { focus: 0, micro: 0, recharge: 0 };
                timerState.legacySessionOffset = 0;
                timerState.sessionsCompleted = 0;
                timerState.totalTimeLogged = 0;
                timerState.currentCycle = 1;
                saveSessionStats();
                saveTimerState();
                updateStatsDisplay();
                updateCycleBadge();
                emitSessionStats();
                emitTimerState('stopped');
                updateDisplay();
                emitTimerUpdate();
            };
            const setPhase = (phase, options = { autoStart: true, duration: null }) => {
                if (!phases[phase]) {
                    return;
                }
                const isMicroBreakInterruption =
                    phase === 'micro' &&
                    timerState.currentPhase === 'focus' &&
                    timerState.timerRunning &&
                    Number.isFinite(timerState.remainingSeconds) &&
                    timerState.remainingSeconds > 0;
                if (isMicroBreakInterruption) {
                    timerState.interruptedFocus = {
                        remainingSeconds: timerState.remainingSeconds,
                        totalDuration: timerState.totalDuration
                    };
                } else if (phase !== 'micro') {
                    clearInterruptedFocus();
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
                    timerState.phaseCounts = normalizePhaseCounts(saved.phaseCounts);
                    if (Number.isFinite(saved.currentCycle) && saved.currentCycle >= 1) {
                        timerState.currentCycle = Math.round(saved.currentCycle);
                    } else {
                        timerState.currentCycle = 1;
                    }
                    if (Number.isFinite(saved.legacySessionOffset) && saved.legacySessionOffset >= 0) {
                        timerState.legacySessionOffset = Math.round(saved.legacySessionOffset);
                    } else if (getPhaseSessionTotal() === 0 && timerState.sessionsCompleted > 0) {
                        timerState.legacySessionOffset = timerState.sessionsCompleted;
                    }
                    saveSessionStats();
                    if (saved.interruptedFocus && typeof saved.interruptedFocus === 'object') {
                        const interruptedRemaining = Number(saved.interruptedFocus.remainingSeconds);
                        const interruptedTotal = Number(saved.interruptedFocus.totalDuration);
                        if (
                            Number.isFinite(interruptedRemaining) && interruptedRemaining > 0 &&
                            Number.isFinite(interruptedTotal) && interruptedTotal > 0
                        ) {
                            timerState.interruptedFocus = {
                                remainingSeconds: Math.round(interruptedRemaining),
                                totalDuration: Math.round(interruptedTotal)
                            };
                        } else {
                            clearInterruptedFocus();
                        }
                    } else {
                        clearInterruptedFocus();
                    }
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
                            recordCompletedPhase(timerState.currentPhase, timerState.totalDuration);
                            clearSavedTimerState();
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
                        applyCycleConfigFromPayload(data.data);
                        applyOverlayTheme(data.data.theme);
                        applyListViewMode(data.data.list_view_mode);
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
            const getOverlayConnectionName = () => {
                if (hasTasklistQuery) {
                    if (streamerFilterParam === 'true') {
                        return 'Working Study - Task List - Streamer';
                    }
                    if (streamerFilterParam === 'false') {
                        return 'Working Study - Task List - Users';
                    }
                    return 'Working Study - Task List - Combined';
                }
                if (hasTimerQuery || !hasTasklistQuery) {
                    return 'Working Study - Timer';
                }
                return 'Working Study - Timer';
            };
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
            // New channel task system helpers
            const channelTasksEndpoint = `${window.location.pathname}?code=${encodeURIComponent(overlayApiKey)}&action=get_channel_tasks`;
            let latestStreamerTasks = [];
            let latestViewerTasks = [];
            const syncTaskPanelVisibility = () => {
                const unified = getListViewMode() === 'unified';
                const streamerPanel = document.querySelector('.study-overlay-page-task-sys-card--streamer');
                const viewerPanel = document.querySelector('.study-overlay-page-task-sys-card--viewer');
                const unifiedPanel = document.querySelector('.study-overlay-page-task-sys-card--unified');
                const setVisible = (panel, visible) => {
                    if (!panel) return;
                    panel.dataset.visible = visible ? 'true' : 'false';
                    panel.style.removeProperty('display');
                };
                setVisible(streamerPanel, !unified && hasTasklistQuery && streamerFilterParam !== 'false');
                setVisible(viewerPanel, !unified && hasTasklistQuery && streamerFilterParam !== 'true');
                setVisible(unifiedPanel, unified && hasTasklistQuery);
            };
            const UNIFIED_OMIT_BACKLOG = true;
            const isBacklogTask = (task) => {
                const status = String(task?.status || '').toLowerCase();
                return status === 'pending';
            };
            const isActiveTask = (task) => String(task?.status || '').toLowerCase() === 'active';
            const isCompletedTask = (task) => String(task?.status || '').toLowerCase() === 'completed';
            const renderUnifiedList = () => {
                const list = document.getElementById('newUnifiedTaskList');
                if (!list) return;
                const streamerName = String(overlayUserName || 'Streamer').trim() || 'Streamer';
                const rows = [];
                (latestStreamerTasks || []).forEach(t => {
                    rows.push({ owner: 'streamer', userName: streamerName, task: t, userId: '' });
                });
                (latestViewerTasks || []).forEach(t => {
                    const taskUserId = (t?.user_id !== undefined && t?.user_id !== null) ? String(t.user_id) : '';
                    rows.push({ owner: 'viewer', userName: String(t?.user_name || '').trim() || 'Unknown', task: t, userId: taskUserId });
                });
                const visibleRows = UNIFIED_OMIT_BACKLOG ? rows.filter(r => !isBacklogTask(r.task)) : rows;
                const rank = r => (isActiveTask(r.task) ? 0 : 1);
                visibleRows.sort((a, b) => rank(a) - rank(b));
                list.innerHTML = '';
                visibleRows.forEach(r => {
                    const t = r.task;
                    const li = document.createElement('li');
                    const done = isCompletedTask(t);
                    const backlog = isBacklogTask(t);
                    li.className = 'study-overlay-page-task-sys-item'
                        + (done ? ' is-done' : '')
                        + (backlog ? ' is-backlog' : '');
                    li.id = 'unified-task-' + r.owner + '-' + t.id;
                    if (r.userId) {
                        li.dataset.userId = r.userId;
                    }
                    const taskDescription = getTaskDescription(t) || 'Untitled task';
                    li.innerHTML = `<div class="study-overlay-page-task-sys-item-check">${getBadgeText(t)}</div><div class="study-overlay-page-task-sys-item-body"><div class="study-overlay-page-task-sys-item-title">${escapeHtml(r.userName)}: ${escapeHtml(taskDescription)}</div>${r.owner === 'viewer' ? projectChipHtml(t) : ''}</div>`;
                    list.appendChild(li);
                });
                // Re-attach any live pomo badges to their viewer rows (rows rebuilt).
                if (typeof attachPomoBadgeToRow === 'function') {
                    (latestViewerTasks || []).forEach(t => {
                        const taskUserId = (t?.user_id !== undefined && t?.user_id !== null) ? String(t.user_id) : '';
                        if (taskUserId) attachPomoBadgeToRow(taskUserId);
                    });
                }
                refreshTaskListAutoScroll();
            };
            const renderTaskLists = () => {
                if (getListViewMode() === 'unified') {
                    renderUnifiedList();
                } else {
                    renderNewStreamerList(latestStreamerTasks || []);
                    renderNewViewerList(latestViewerTasks || []);
                }
            };
            const loadChannelTasks = async () => {
                // Only fetch if at least one task panel exists in the DOM
                const sPanel = document.getElementById('newStreamerTaskList');
                const vPanel = document.getElementById('newViewerTaskList');
                const uPanel = document.getElementById('newUnifiedTaskList');
                if (!sPanel && !vPanel && !uPanel) return;
                try {
                    const r = await fetch(channelTasksEndpoint);
                    const j = await r.json();
                    if (j.success) {
                        latestStreamerTasks = j.streamer_tasks || [];
                        latestViewerTasks = j.user_tasks || [];
                        renderTaskLists();
                    }
                } catch (e) { console.warn('[Overlay] loadChannelTasks error', e); }
            };
            const taskListAutoScrollState = new Map();
            const stopTaskListAutoScroll = (listId) => {
                const state = taskListAutoScrollState.get(listId);
                if (!state) return;
                if (state.intervalId) clearInterval(state.intervalId);
                if (state.pauseTimeoutId) clearTimeout(state.pauseTimeoutId);
                taskListAutoScrollState.delete(listId);
            };
            const ensureTaskListAutoScroll = (listId) => {
                const list = document.getElementById(listId);
                if (!list) {
                    stopTaskListAutoScroll(listId);
                    return;
                }
                const taskCount = list.querySelectorAll('.study-overlay-page-task-sys-item').length;
                const maxScroll = Math.max(0, list.scrollHeight - list.clientHeight);
                const shouldAutoScroll = taskCount > 5 && maxScroll > 0;
                if (!shouldAutoScroll) {
                    stopTaskListAutoScroll(listId);
                    list.scrollTop = 0;
                    return;
                }
                if (taskListAutoScrollState.has(listId)) {
                    return;
                }
                const state = {
                    direction: 1,
                    intervalId: null,
                    pauseTimeoutId: null,
                };
                const pauseAndReverse = (nextDirection) => {
                    if (state.intervalId) {
                        clearInterval(state.intervalId);
                        state.intervalId = null;
                    }
                    if (state.pauseTimeoutId) {
                        clearTimeout(state.pauseTimeoutId);
                        state.pauseTimeoutId = null;
                    }
                    state.pauseTimeoutId = setTimeout(() => {
                        state.direction = nextDirection;
                        state.intervalId = setInterval(tick, 28);
                    }, 850);
                };
                const tick = () => {
                    const currentList = document.getElementById(listId);
                    if (!currentList) {
                        stopTaskListAutoScroll(listId);
                        return;
                    }
                    const currentTaskCount = currentList.querySelectorAll('.study-overlay-page-task-sys-item').length;
                    const currentMaxScroll = Math.max(0, currentList.scrollHeight - currentList.clientHeight);
                    if (currentTaskCount <= 5 || currentMaxScroll <= 0) {
                        stopTaskListAutoScroll(listId);
                        currentList.scrollTop = 0;
                        return;
                    }
                    currentList.scrollTop += state.direction;
                    if (currentList.scrollTop >= currentMaxScroll) {
                        currentList.scrollTop = currentMaxScroll;
                        pauseAndReverse(-1);
                    } else if (currentList.scrollTop <= 0) {
                        currentList.scrollTop = 0;
                        pauseAndReverse(1);
                    }
                };
                state.intervalId = setInterval(tick, 28);
                taskListAutoScrollState.set(listId, state);
            };
            const refreshTaskListAutoScroll = () => {
                window.requestAnimationFrame(() => {
                    ensureTaskListAutoScroll('newStreamerTaskList');
                    ensureTaskListAutoScroll('newViewerTaskList');
                    ensureTaskListAutoScroll('newUnifiedTaskList');
                });
            };
            const renderNewStreamerList = (tasks) => {
                const list = document.getElementById('newStreamerTaskList');
                if (!list) return;
                list.innerHTML = '';
                const sortedTasks = [...tasks].sort((a, b) => {
                    const rankA = isActiveTask(a) ? 0 : isBacklogTask(a) ? 1 : 2;
                    const rankB = isActiveTask(b) ? 0 : isBacklogTask(b) ? 1 : 2;
                    if (rankA !== rankB) return rankA - rankB;
                    // Within backlog, sort by position ascending
                    const posA = a.backlog_position ?? Infinity;
                    const posB = b.backlog_position ?? Infinity;
                    return posA - posB;
                });
                sortedTasks.forEach(t => newStreamerUpsert(t));
                refreshTaskListAutoScroll();
            };
            const renderNewViewerList = (tasks) => {
                const list = document.getElementById('newViewerTaskList');
                if (!list) return;
                list.innerHTML = '';
                const sortedTasks = [...tasks].sort((a, b) => {
                    const nameA = String(a.user_name || '').toLowerCase();
                    const nameB = String(b.user_name || '').toLowerCase();
                    if (nameA < nameB) return -1;
                    if (nameA > nameB) return 1;
                    const rankA = String(a.status || '').toLowerCase() === 'active' ? 0 : 1;
                    const rankB = String(b.status || '').toLowerCase() === 'active' ? 0 : 1;
                    if (rankA !== rankB) return rankA - rankB;
                    return b.id - a.id;
                });
                let lastUserName = null;
                sortedTasks.forEach(t => {
                    const userName = String(t.user_name || '').trim() || 'Unknown';
                    const nameLower = userName.toLowerCase();
                    if (lastUserName !== nameLower) {
                        lastUserName = nameLower;
                        const headerLi = document.createElement('li');
                        headerLi.className = 'study-overlay-page-task-sys-user-header';
                        headerLi.innerHTML = escapeHtml(userName);
                        list.appendChild(headerLi);
                    }
                    newViewerUpsert(t);
                });
                refreshTaskListAutoScroll();
            };
            const getTaskDescription = (task) => {
                const description = String(task?.description || '').trim();
                if (description) return description;
                return String(task?.title || '').trim();
            };
            const getBadgeText = (task) => {
                return task.id ?? '';
            };
            // /notify transport JSON-encodes the nested task dict — decode if needed.
            const parseTaskPayload = (raw) => {
                if (typeof raw === 'string') {
                    try { return JSON.parse(raw); } catch (e) { return null; }
                }
                return raw || null;
            };
            const projectChipHtml = (task) => {
                const project = String(task?.project || '').trim();
                if (!project) return '';
                return `<span class="study-overlay-page-task-sys-item-project">${escapeHtml(project)}</span>`;
            };
            const newStreamerUpsert = (task) => {
                const list = document.getElementById('newStreamerTaskList');
                if (!list) return;
                let li = document.getElementById('new-streamer-task-' + task.id);
                if (!li) { li = document.createElement('li'); li.id = 'new-streamer-task-' + task.id; list.appendChild(li); }
                const done = isCompletedTask(task);
                const backlog = isBacklogTask(task);
                li.className = 'study-overlay-page-task-sys-item' + (done ? ' is-done' : '') + (backlog ? ' is-backlog' : '');
                const taskDescription = getTaskDescription(task) || 'Untitled task';
                li.innerHTML = `<div class="study-overlay-page-task-sys-item-check">${getBadgeText(task)}</div><div class="study-overlay-page-task-sys-item-body"><div class="study-overlay-page-task-sys-item-title">${escapeHtml(taskDescription)}</div></div>`;
                refreshTaskListAutoScroll();
            };
            const newViewerUpsert = (task) => {
                const list = document.getElementById('newViewerTaskList');
                if (!list) return;
                let li = document.getElementById('new-viewer-task-' + task.id);
                if (!li) { li = document.createElement('li'); li.id = 'new-viewer-task-' + task.id; list.appendChild(li); }
                const done = isCompletedTask(task);
                const backlog = isBacklogTask(task);
                li.className = 'study-overlay-page-task-sys-item' + (done ? ' is-done' : '') + (backlog ? ' is-backlog' : '');
                const userName = String(task?.user_name || '').trim() || 'Unknown';
                const taskDescription = getTaskDescription(task) || 'Untitled task';
                const taskUserId = task?.user_id !== undefined && task?.user_id !== null
                    ? String(task.user_id)
                    : '';
                if (taskUserId) {
                    li.dataset.userId = taskUserId;
                } else {
                    delete li.dataset.userId;
                }
                li.innerHTML = `<div class="study-overlay-page-task-sys-item-check">${getBadgeText(task)}</div><div class="study-overlay-page-task-sys-item-body"><div class="study-overlay-page-task-sys-item-title">${escapeHtml(taskDescription)}</div>${projectChipHtml(task)}</div>`;
                if (typeof attachPomoBadgeToRow === 'function') {
                    attachPomoBadgeToRow(taskUserId);
                }
                refreshTaskListAutoScroll();
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
                const container = document.getElementById('taskRewardPopups') || document.body;
                // Cap visible stack so rapid rewards don't flood the overlay.
                // column-reverse means firstElementChild is the oldest still on screen.
                while (container.querySelectorAll('.study-overlay-page-task-sys-reward:not(.is-leaving)').length >= 5) {
                    container.firstElementChild?.remove();
                }
                const el = document.createElement('div');
                el.className = 'study-overlay-page-task-sys-reward';
                el.textContent = msg;
                container.appendChild(el);
                setTimeout(() => {
                    el.classList.add('is-leaving');
                    setTimeout(() => el.remove(), 400);
                }, 4600);
            };
            const pomoState = new Map(); // user_id -> { remainingSeconds, phase, label, currentCycle, totalCycles, userName, status, completedTimeoutId }
            let pomoTickerId = null;
            const getPomoPhaseColor = phase => {
                const varName = phase === 'break' ? '--micro-color' : '--focus-color';
                const fallback = phase === 'break' ? '#6be9ff' : '#ff9161';
                try {
                    const value = getComputedStyle(overlayRoot).getPropertyValue(varName).trim();
                    if (value) {
                        return value;
                    }
                } catch (error) {
                    /* getComputedStyle unavailable — fall through to the static colour */
                }
                return fallback;
            };
            const formatPomoRemaining = seconds => {
                const safe = Math.max(0, Math.floor(Number(seconds) || 0));
                if (safe < 60) {
                    // Sub-minute: show seconds so the badge doesn't sit on "0m".
                    return `${safe}s`;
                }
                return `${Math.floor(safe / 60)}m`;
            };
            const findPomoRow = userId => {
                const key = String(userId || '');
                if (!key) {
                    return null;
                }
                const listIds = ['newViewerTaskList', 'newUnifiedTaskList'];
                for (const listId of listIds) {
                    const list = document.getElementById(listId);
                    if (!list) {
                        continue;
                    }
                    const rows = list.querySelectorAll('.study-overlay-page-task-sys-item[data-user-id]');
                    for (const row of rows) {
                        if (row.dataset.userId === key) {
                            return row;
                        }
                    }
                }
                return null;
            };
            const renderPomoBadge = userId => {
                const key = String(userId || '');
                const state = pomoState.get(key);
                if (!state) {
                    const orphan = document.getElementById('pomo-badge-' + key);
                    if (orphan) orphan.remove();
                    return;
                }
                const row = findPomoRow(key);
                if (!row) {
                    // No visible row yet — keep the state, drop any stale badge node.
                    const orphan = document.getElementById('pomo-badge-' + key);
                    if (orphan) orphan.remove();
                    return;
                }
                const body = row.querySelector('.study-overlay-page-task-sys-item-body') || row;
                let badge = document.getElementById('pomo-badge-' + key);
                if (!badge || badge.parentElement !== body) {
                    if (badge) badge.remove();
                    badge = document.createElement('div');
                    badge.id = 'pomo-badge-' + key;
                    badge.className = 'study-overlay-page-pomo-badge';
                    body.appendChild(badge);
                }
                const phase = state.phase === 'break' ? 'break' : 'work';
                const isCompleted = state.status === 'completed' || state.phase === 'completed';
                badge.dataset.phase = isCompleted ? 'completed' : phase;
                const ringColor = isCompleted ? getPomoPhaseColor('work') : getPomoPhaseColor(phase);
                badge.style.setProperty('--pomo-ring-color', ringColor);
                let cycleSuffix = '';
                if (Number.isFinite(state.totalCycles) && state.totalCycles > 1) {
                    cycleSuffix = ` <span class="study-overlay-page-pomo-badge-cycle">${state.currentCycle}/${state.totalCycles}</span>`;
                }
                if (isCompleted) {
                    badge.innerHTML = `<span class="study-overlay-page-pomo-badge-ring"></span><span class="study-overlay-page-pomo-badge-text">✓ done</span>`;
                    return;
                }
                const phaseTag = phase === 'break' ? 'break' : '';
                const labelHtml = state.label
                    ? ` <span class="study-overlay-page-pomo-badge-label">${escapeHtml(state.label)}</span>`
                    : '';
                badge.innerHTML = `<span class="study-overlay-page-pomo-badge-ring"></span>`
                    + `<span class="study-overlay-page-pomo-badge-text">${escapeHtml(formatPomoRemaining(state.remainingSeconds))}</span>`
                    + (phaseTag ? ` <span class="study-overlay-page-pomo-badge-phase">${phaseTag}</span>` : '')
                    + labelHtml + cycleSuffix;
            };
            const attachPomoBadgeToRow = userId => {
                const key = String(userId || '');
                if (!key || !pomoState.has(key)) {
                    return;
                }
                renderPomoBadge(key);
            };
            const ensurePomoTicker = () => {
                if (pomoTickerId !== null) {
                    return;
                }
                pomoTickerId = setInterval(() => {
                    let anyActive = false;
                    pomoState.forEach((state, key) => {
                        if (state.status !== 'active') {
                            return;
                        }
                        anyActive = true;
                        const next = Math.max(0, (Number(state.remainingSeconds) || 0) - 1);
                        if (next !== state.remainingSeconds) {
                            state.remainingSeconds = next;
                            renderPomoBadge(key);
                        }
                    });
                    if (!anyActive) {
                        stopPomoTicker();
                    }
                }, 1000);
            };
            const stopPomoTicker = () => {
                if (pomoTickerId !== null) {
                    clearInterval(pomoTickerId);
                    pomoTickerId = null;
                }
            };
            const clearPomoBadge = userId => {
                const key = String(userId || '');
                if (!key) {
                    return;
                }
                const existing = pomoState.get(key);
                if (existing && existing.completedTimeoutId) {
                    clearTimeout(existing.completedTimeoutId);
                }
                pomoState.delete(key);
                const node = document.getElementById('pomo-badge-' + key);
                if (node) node.remove();
                // Idle the shared ticker if nothing is left running.
                let anyActive = false;
                pomoState.forEach(state => { if (state.status === 'active') anyActive = true; });
                if (!anyActive) stopPomoTicker();
            };
            const applyPomoPayload = (payload, { replace = false } = {}) => {
                if (!payload || payload.user_id === undefined || payload.user_id === null) {
                    return;
                }
                const key = String(payload.user_id);
                const phase = payload.current_phase === 'break' ? 'break' : 'work';
                const remaining = Math.max(0, Math.floor(Number(payload.remaining_seconds) || 0));
                const status = payload.status === 'completed' || payload.status === 'cancelled'
                    ? payload.status
                    : 'active';
                let state = pomoState.get(key);
                if (!state || replace) {
                    if (state && state.completedTimeoutId) {
                        clearTimeout(state.completedTimeoutId);
                    }
                    state = {
                        remainingSeconds: remaining,
                        phase,
                        label: null,
                        currentCycle: 1,
                        totalCycles: 1,
                        userName: '',
                        status: 'active',
                        completedTimeoutId: null
                    };
                    pomoState.set(key, state);
                }
                state.remainingSeconds = remaining;
                state.phase = phase;
                state.status = status;
                state.label = (payload.label !== undefined && payload.label !== null && String(payload.label).trim())
                    ? String(payload.label).trim()
                    : null;
                if (payload.user_name) {
                    state.userName = String(payload.user_name);
                }
                const currentCycle = Number(payload.current_cycle);
                state.currentCycle = Number.isFinite(currentCycle) && currentCycle >= 1 ? Math.round(currentCycle) : 1;
                const totalCycles = Number(payload.total_cycles);
                state.totalCycles = Number.isFinite(totalCycles) && totalCycles >= 1 ? Math.round(totalCycles) : 1;
                renderPomoBadge(key);
                if (state.status === 'active') {
                    ensurePomoTicker();
                }
            };
            const connect = () => {
                setConnectionStatus('Connecting…', 'connecting');
                console.log('[Overlay] Creating socket connection...');
                socket = io('wss://websocket.botofthespecter.com', { reconnection: false });
                console.log('[Overlay] Socket instance created, registering event listeners...');
                socket.on('connect', () => {
                    reconnectAttempts = 0;
                    const overlayConnectionName = getOverlayConnectionName();
                    console.log('[Overlay] Connected to socket server! ID:', socket.id);
                    setConnectionStatus('Connected', 'connected');
                    socket.emit('REGISTER', {
                        code: overlayApiKey,
                        channel: 'Overlay',
                        name: overlayConnectionName
                    });
                    console.log('[Overlay] Sent REGISTER event for code/name:', overlayApiKey, overlayConnectionName);
                    emitSessionStats();
                    syncTaskPanelVisibility();
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
                    applyCycleConfigFromPayload(payload);
                    if (payload.theme !== undefined) {
                        applyOverlayTheme(payload.theme);
                    }
                    if (payload.list_view_mode !== undefined) {
                        applyListViewMode(payload.list_view_mode);
                    }
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
                    } else if (action === 'reset_all') {
                        resetAllState(payload);
                    } else if (action === 'stop') {
                        stopTimer();
                    } else if (action === 'start') {
                        const durationOverride = typeof overrideDuration === 'number' ? overrideDuration : timerState.durations[timerState.currentPhase];
                        setPhase(timerState.currentPhase, { autoStart: true, duration: durationOverride });
                    }
                };
                socket.on('SPECTER_STATS_REQUEST', () => {
                    emitSessionStats();
                    if (timerState.timerRunning) {
                        emitTimerState('running');
                    } else if (timerState.timerPaused) {
                        emitTimerState('paused');
                    } else {
                        emitTimerState('stopped');
                    }
                    emitTimerUpdate();
                });
                socket.on('TASK_LIST_SYNC', (d) => {
                    const hasContent = ((d?.streamer_tasks || []).length + (d?.user_tasks || []).length) > 0;
                    if (!hasContent) return; // an empty sync would wipe lists loadChannelTasks() just rendered
                    latestStreamerTasks = d.streamer_tasks || [];
                    latestViewerTasks = d.user_tasks || [];
                    renderTaskLists();
                });
                socket.on('PROJECT_UPDATE', () => {
                    loadChannelTasks();
                });
                socket.on('TASK_CREATE', (d) => {
                    loadChannelTasks();
                });
                socket.on('TASK_UPDATE', (d) => {
                    loadChannelTasks();
                });
                socket.on('TASK_COMPLETE', (d) => {
                    loadChannelTasks();
                });
                socket.on('TASK_APPROVE', (d) => {
                    loadChannelTasks();
                });
                socket.on('TASK_REJECT', (d) => {
                    loadChannelTasks();
                });
                socket.on('TASK_DELETE', (d) => {
                    loadChannelTasks();
                });
                socket.on('TASK_REWARD_CONFIRM', (d) => {
                    showTaskRewardPopup('\uD83C\uDFC6 ' + d.user_name + ' earned ' + d.points_awarded + ' pts!');
                });
                socket.on('USER_POMO_START', (d) => {
                    applyPomoPayload(d, { replace: true });
                });
                socket.on('USER_POMO_UPDATE', (d) => {
                    applyPomoPayload(d);
                });
                socket.on('USER_POMO_PHASE', (d) => {
                    applyPomoPayload(d);
                });
                socket.on('USER_POMO_COMPLETE', (d) => {
                    if (!d || d.user_id === undefined || d.user_id === null) {
                        return;
                    }
                    const key = String(d.user_id);
                    // Resync first so the badge reflects the final state, then
                    // flip it to a completed badge and clear after a brief beat.
                    applyPomoPayload(d);
                    const state = pomoState.get(key);
                    if (!state) {
                        return;
                    }
                    state.status = 'completed';
                    state.phase = 'completed';
                    state.remainingSeconds = 0;
                    renderPomoBadge(key);
                    // Idle the ticker if nothing else is active.
                    let anyActive = false;
                    pomoState.forEach(s => { if (s.status === 'active') anyActive = true; });
                    if (!anyActive) stopPomoTicker();
                    if (state.completedTimeoutId) {
                        clearTimeout(state.completedTimeoutId);
                    }
                    state.completedTimeoutId = setTimeout(() => clearPomoBadge(key), 6000);
                });
                socket.on('USER_POMO_CANCEL', (d) => {
                    if (!d || d.user_id === undefined || d.user_id === null) {
                        return;
                    }
                    clearPomoBadge(d.user_id);
                });
                socket.on('SPECTER_SESSION_STATS', payload => {
                    if (!payload) return;
                    if (payload.phaseCounts && typeof payload.phaseCounts === 'object') {
                        timerState.phaseCounts = normalizePhaseCounts(payload.phaseCounts);
                        timerState.legacySessionOffset = 0;
                    }
                    if (typeof payload.sessionsCompleted === 'number') {
                        timerState.sessionsCompleted = payload.sessionsCompleted;
                        if (getPhaseSessionTotal() === 0 && timerState.sessionsCompleted > 0) {
                            timerState.legacySessionOffset = Math.round(timerState.sessionsCompleted);
                        }
                    }
                    if (typeof payload.totalTimeLogged === 'number') {
                        timerState.totalTimeLogged = payload.totalTimeLogged;
                    }
                    saveSessionStats();
                    updateStatsDisplay();
                });
            };
            restoreSavedSessionStats();
            hasRestoredTimerState = restoreSavedTimerState();
            connect();
            updateStatsDisplay();
            updateDisplay();
        })();
    </script>
</body>
</html>