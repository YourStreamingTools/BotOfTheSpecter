<?php
ob_start(); // Capture any output from includes (e.g. database setup messages) so it doesn't corrupt POST JSON responses
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
    // Discard any text output by the database setup includes before we send JSON headers
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    $action = $_POST['action'];
    // Verify database connection exists
    if (!isset($db) || !$db) {
        echo json_encode(['success' => false, 'error' => 'Database connection not available']);
        exit;
    }
    if ($action === 'get_settings') {
        // Load timer settings from database
        $stmt = $db->prepare("SELECT focus_minutes, micro_break_minutes, recharge_break_minutes, reward_enabled, reward_points_per_task FROM working_study_overlay_settings LIMIT 1");
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
                'recharge_break_minutes' => 30,
                'reward_enabled' => 0,
                'reward_points_per_task' => 10
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
        $rewardEnabled = !empty($_POST['reward_enabled']) ? 1 : 0;
        $rewardPoints = max(0, intval($_POST['reward_points_per_task'] ?? 10));
        $stmt = $db->prepare("UPDATE working_study_overlay_settings SET focus_minutes = ?, micro_break_minutes = ?, recharge_break_minutes = ?, reward_enabled = ?, reward_points_per_task = ? WHERE id = 1");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $db->error]);
            exit;
        }
        $stmt->bind_param("iiiii", $focus, $micro, $recharge, $rewardEnabled, $rewardPoints);
        $success = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $success]);
        exit;
    }
    if ($action === 'get_tasks') {
        // Load all tasks for this channel database
        $stmt = $db->prepare("SELECT username, user_id, task_id as id, title, priority, completed FROM working_study_overlay_tasks ORDER BY created_at DESC");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $db->error]);
            exit;
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $tasks = [];
        while ($row = $result->fetch_assoc()) {
            $tasks[] = [
                'username' => $row['username'],
                'user_id' => $row['user_id'],
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
        $sessionUserId = (string) ($_SESSION['twitchUserId'] ?? '');
        $tasks = json_decode($_POST['tasks'] ?? '[]', true);
        if (!is_array($tasks)) {
            echo json_encode(['success' => false, 'error' => 'Invalid tasks format']);
            exit;
        }
        $rewardEnabled = 0;
        $rewardPoints = 0;
        $settingsStmt = $db->prepare("SELECT reward_enabled, reward_points_per_task FROM working_study_overlay_settings LIMIT 1");
        if ($settingsStmt) {
            $settingsStmt->execute();
            $settingsResult = $settingsStmt->get_result();
            if ($settingsRow = $settingsResult->fetch_assoc()) {
                $rewardEnabled = !empty($settingsRow['reward_enabled']) ? 1 : 0;
                $rewardPoints = max(0, intval($settingsRow['reward_points_per_task'] ?? 0));
            }
            $settingsStmt->close();
        }

        $db->begin_transaction();
        try {
            // Clear existing tasks for this user only
            $deleteStmt = $db->prepare("DELETE FROM working_study_overlay_tasks WHERE username = ?");
            if (!$deleteStmt) {
                throw new Exception('Prepare failed: ' . $db->error);
            }
            $deleteStmt->bind_param("s", $username);
            if (!$deleteStmt->execute()) {
                throw new Exception('Execute failed: ' . $deleteStmt->error);
            }
            $deleteStmt->close();

            // Insert new tasks for this user
            $insertStmt = $db->prepare("INSERT INTO working_study_overlay_tasks (username, user_id, task_id, title, priority, completed) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$insertStmt) {
                throw new Exception('Prepare failed: ' . $db->error);
            }

            $rewardCheckStmt = $db->prepare("SELECT id FROM working_study_overlay_task_rewards WHERE username = ? AND task_id = ? LIMIT 1");
            if (!$rewardCheckStmt) {
                throw new Exception('Prepare failed: ' . $db->error);
            }

            $rewardInsertStmt = $db->prepare("INSERT INTO working_study_overlay_task_rewards (username, user_id, task_id, points_awarded) VALUES (?, ?, ?, ?)");
            if (!$rewardInsertStmt) {
                throw new Exception('Prepare failed: ' . $db->error);
            }

            $pointsSelectStmt = $db->prepare("SELECT points FROM bot_points WHERE user_id = ? LIMIT 1");
            if (!$pointsSelectStmt) {
                throw new Exception('Prepare failed: ' . $db->error);
            }

            $pointsUpdateStmt = $db->prepare("UPDATE bot_points SET points = ?, user_name = ? WHERE user_id = ?");
            if (!$pointsUpdateStmt) {
                throw new Exception('Prepare failed: ' . $db->error);
            }

            $pointsInsertStmt = $db->prepare("INSERT INTO bot_points (user_id, user_name, points) VALUES (?, ?, ?)");
            if (!$pointsInsertStmt) {
                throw new Exception('Prepare failed: ' . $db->error);
            }

            $pointsAwardedTotal = 0;

            foreach ($tasks as $task) {
                $task_id = (string) ($task['id'] ?? '');
                $title = (string) ($task['title'] ?? '');
                $priority = (string) ($task['priority'] ?? 'medium');
                $completed = !empty($task['completed']) ? 1 : 0;
                $taskUserId = (string) ($task['user_id'] ?? $sessionUserId);

                if ($task_id === '' || $title === '') {
                    continue;
                }

                $insertStmt->bind_param("sssssi", $username, $taskUserId, $task_id, $title, $priority, $completed);
                if (!$insertStmt->execute()) {
                    throw new Exception('Execute failed: ' . $insertStmt->error);
                }

                if (!$rewardEnabled || $rewardPoints <= 0 || !$completed || $taskUserId === '') {
                    continue;
                }

                $rewardCheckStmt->bind_param("ss", $username, $task_id);
                if (!$rewardCheckStmt->execute()) {
                    throw new Exception('Execute failed: ' . $rewardCheckStmt->error);
                }
                $rewardResult = $rewardCheckStmt->get_result();
                if ($rewardResult && $rewardResult->num_rows > 0) {
                    continue;
                }

                $rewardInsertStmt->bind_param("sssi", $username, $taskUserId, $task_id, $rewardPoints);
                if (!$rewardInsertStmt->execute()) {
                    throw new Exception('Execute failed: ' . $rewardInsertStmt->error);
                }

                $pointsSelectStmt->bind_param("s", $taskUserId);
                if (!$pointsSelectStmt->execute()) {
                    throw new Exception('Execute failed: ' . $pointsSelectStmt->error);
                }
                $pointsResult = $pointsSelectStmt->get_result();

                if ($pointsRow = $pointsResult->fetch_assoc()) {
                    $newPoints = intval($pointsRow['points'] ?? 0) + $rewardPoints;
                    $pointsUpdateStmt->bind_param("iss", $newPoints, $username, $taskUserId);
                    if (!$pointsUpdateStmt->execute()) {
                        throw new Exception('Execute failed: ' . $pointsUpdateStmt->error);
                    }
                } else {
                    $pointsInsertStmt->bind_param("ssi", $taskUserId, $username, $rewardPoints);
                    if (!$pointsInsertStmt->execute()) {
                        throw new Exception('Execute failed: ' . $pointsInsertStmt->error);
                    }
                }

                $pointsAwardedTotal += $rewardPoints;
            }

            $insertStmt->close();
            $rewardCheckStmt->close();
            $rewardInsertStmt->close();
            $pointsSelectStmt->close();
            $pointsUpdateStmt->close();
            $pointsInsertStmt->close();

            $db->commit();
            echo json_encode(['success' => true, 'points_awarded' => $pointsAwardedTotal]);
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    // ── Channel Task System API (prefixed ch_) ────────────────────────────────
    if ($action === 'ch_get_settings') {
        $stmt = $db->prepare("SELECT require_approval, default_reward_points, allow_user_tasks, task_visible_overlay FROM task_settings LIMIT 1");
        if (!$stmt) { echo json_encode(['success' => false, 'error' => $db->error]); exit; }
        $stmt->execute();
        $settings = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$settings) {
            $settings = ['require_approval' => 0, 'default_reward_points' => 50, 'allow_user_tasks' => 1, 'task_visible_overlay' => 1];
        }
        echo json_encode(['success' => true, 'data' => $settings]);
        exit;
    }
    if ($action === 'ch_save_settings') {
        $require_approval     = !empty($_POST['require_approval'])     ? 1 : 0;
        $default_reward       = max(0, intval($_POST['default_reward_points'] ?? 50));
        $allow_user_tasks     = !empty($_POST['allow_user_tasks'])     ? 1 : 0;
        $task_visible_overlay = !empty($_POST['task_visible_overlay']) ? 1 : 0;
        $stmt = $db->prepare("UPDATE task_settings SET require_approval=?, default_reward_points=?, allow_user_tasks=?, task_visible_overlay=? WHERE id=1");
        if (!$stmt) { echo json_encode(['success' => false, 'error' => $db->error]); exit; }
        $stmt->bind_param("iiii", $require_approval, $default_reward, $allow_user_tasks, $task_visible_overlay);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $ok]);
        exit;
    }
    if ($action === 'ch_get_tasks') {
        try {
            $streamer_tasks = [];
            $user_tasks     = [];
            // Guard: only query if the tables exist
            $st_exists_res = $db->query("SHOW TABLES LIKE 'streamer_tasks'");
            $ut_exists_res = $db->query("SHOW TABLES LIKE 'user_tasks'");
            if ($st_exists_res && $st_exists_res->num_rows > 0) {
                $st = $db->prepare("SELECT id, title, description, category, status, reward_points, created_at FROM streamer_tasks ORDER BY created_at DESC");
                if ($st && $st->execute()) {
                    $res = $st->get_result();
                    if ($res) { $streamer_tasks = $res->fetch_all(MYSQLI_ASSOC); }
                    $st->close();
                }
            }
            if ($ut_exists_res && $ut_exists_res->num_rows > 0) {
                $ut = $db->prepare("SELECT id, streamer_task_id, user_id, user_name, title, description, category, status, approval_status, reward_points, completed_at, created_at FROM user_tasks ORDER BY created_at DESC");
                if ($ut && $ut->execute()) {
                    $res = $ut->get_result();
                    if ($res) { $user_tasks = $res->fetch_all(MYSQLI_ASSOC); }
                    $ut->close();
                }
            }
            echo json_encode(['success' => true, 'streamer_tasks' => $streamer_tasks, 'user_tasks' => $user_tasks]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    if ($action === 'ch_create_streamer_task') {
        $title    = trim($_POST['title'] ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $category = trim($_POST['category'] ?? 'General');
        $points   = max(0, intval($_POST['reward_points'] ?? 0));
        if (!$title) { echo json_encode(['success' => false, 'error' => 'Title required']); exit; }
        $stmt = $db->prepare("INSERT INTO streamer_tasks (title, description, category, reward_points) VALUES (?, ?, ?, ?)");
        if (!$stmt) { echo json_encode(['success' => false, 'error' => $db->error]); exit; }
        $stmt->bind_param("sssi", $title, $desc, $category, $points);
        $ok = $stmt->execute(); $id = $db->insert_id; $stmt->close();
        echo json_encode(['success' => $ok, 'id' => $id]);
        exit;
    }
    if ($action === 'ch_update_streamer_task') {
        $id     = intval($_POST['id'] ?? 0);
        $title  = trim($_POST['title'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $cat    = trim($_POST['category'] ?? 'General');
        $pts    = max(0, intval($_POST['reward_points'] ?? 0));
        $status = in_array($_POST['status'] ?? '', ['active','completed','hidden']) ? $_POST['status'] : 'active';
        if (!$id || !$title) { echo json_encode(['success' => false, 'error' => 'Invalid data']); exit; }
        $stmt = $db->prepare("UPDATE streamer_tasks SET title=?, description=?, category=?, reward_points=?, status=? WHERE id=?");
        if (!$stmt) { echo json_encode(['success' => false, 'error' => $db->error]); exit; }
        $stmt->bind_param("sssisi", $title, $desc, $cat, $pts, $status, $id);
        $ok = $stmt->execute(); $stmt->close();
        echo json_encode(['success' => $ok]);
        exit;
    }
    if ($action === 'ch_delete_streamer_task') {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); exit; }
        $stmt = $db->prepare("DELETE FROM streamer_tasks WHERE id=?");
        $stmt->bind_param("i", $id); $ok = $stmt->execute(); $stmt->close();
        echo json_encode(['success' => $ok]);
        exit;
    }
    if ($action === 'ch_approve_user_task') {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); exit; }
        $stmt = $db->prepare("UPDATE user_tasks SET approval_status='approved' WHERE id=?");
        $stmt->bind_param("i", $id); $ok = $stmt->execute(); $stmt->close();
        $rs = $db->prepare("SELECT id, user_id, user_name, title, reward_points FROM user_tasks WHERE id=?");
        $rs->bind_param("i", $id); $rs->execute();
        $task = $rs->get_result()->fetch_assoc(); $rs->close();
        echo json_encode(['success' => $ok, 'task' => $task]);
        exit;
    }
    if ($action === 'ch_reject_user_task') {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); exit; }
        $stmt = $db->prepare("UPDATE user_tasks SET approval_status='rejected', status='rejected' WHERE id=?");
        $stmt->bind_param("i", $id); $ok = $stmt->execute(); $stmt->close();
        echo json_encode(['success' => $ok]);
        exit;
    }
    if ($action === 'ch_complete_user_task') {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); exit; }
        $stmt = $db->prepare("UPDATE user_tasks SET status='completed', completed_at=NOW() WHERE id=?");
        $stmt->bind_param("i", $id); $ok = $stmt->execute(); $stmt->close();
        $rs = $db->prepare("SELECT id, user_id, user_name, title, reward_points, approval_status FROM user_tasks WHERE id=?");
        $rs->bind_param("i", $id); $rs->execute();
        $task = $rs->get_result()->fetch_assoc(); $rs->close();
        echo json_encode(['success' => $ok, 'task' => $task]);
        exit;
    }
    if ($action === 'ch_complete_streamer_task') {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); exit; }
        $stmt = $db->prepare("UPDATE streamer_tasks SET status='completed' WHERE id=?");
        $stmt->bind_param("i", $id); $ok = $stmt->execute(); $stmt->close();
        echo json_encode(['success' => $ok]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// Load initial settings for page initialization
$initialSettings = ['focus_minutes' => 60, 'micro_break_minutes' => 5, 'recharge_break_minutes' => 30, 'reward_enabled' => 0, 'reward_points_per_task' => 10];
if ($db) {
    $stmt = $db->prepare("SELECT focus_minutes, micro_break_minutes, recharge_break_minutes, reward_enabled, reward_points_per_task FROM working_study_overlay_settings LIMIT 1");
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
    $stmt = $db->prepare("SELECT username, user_id, task_id as id, title, priority, completed FROM working_study_overlay_tasks ORDER BY created_at DESC");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $initialTasks[] = [
                'username' => $row['username'],
                'user_id' => $row['user_id'],
                'id' => $row['id'],
                'title' => $row['title'],
                'priority' => $row['priority'],
                'completed' => (bool) $row['completed']
            ];
        }
        $stmt->close();
    }
}

// Load initial task system settings
$chInitialSettings = ['require_approval' => 0, 'default_reward_points' => 50, 'allow_user_tasks' => 1, 'task_visible_overlay' => 1];
if ($db) {
    $stmt = $db->prepare("SELECT require_approval, default_reward_points, allow_user_tasks, task_visible_overlay FROM task_settings LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) { $chInitialSettings = $row; }
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
    <!-- ═══════════════════════════════════════════════════════════════════════
         Channel Task Manager (streamer_tasks + user_tasks)
    ══════════════════════════════════════════════════════════════════════════ -->
    <div class="card mt-5">
        <header class="card-header">
            <p class="card-header-title">
                <span class="icon mr-2"><i class="fas fa-tasks"></i></span>
                Channel Task Manager
            </p>
            <div class="card-header-icon buttons" style="padding: 0.25rem 0.75rem; margin: 0;">
                <button type="button" class="button is-info is-small" id="copyTasklistCombinedBtn">
                    <span class="icon"><i class="fas fa-copy" aria-hidden="true"></i></span>
                    <span>Copy Link (Combined)</span>
                </button>
                <button type="button" class="button is-info is-small" id="copyTasklistLinkBtn">
                    <span class="icon"><i class="fas fa-copy" aria-hidden="true"></i></span>
                    <span>Copy Link (Streamer)</span>
                </button>
                <button type="button" class="button is-info is-small" id="copyTasklistUserLinkBtn">
                    <span class="icon"><i class="fas fa-copy" aria-hidden="true"></i></span>
                    <span>Copy Link (Users)</span>
                </button>
                <button class="button is-ghost is-small" id="chToggleSettingsBtn" aria-label="toggle task settings">
                    <span class="icon"><i class="fas fa-angle-down" id="chSettingsChevron"></i></span>
                </button>
            </div>
        </header>
        <!-- Settings sub-panel -->
        <div class="card-content" id="chSettingsPanel">
            <div class="columns is-multiline">
                <div class="column is-half">
                    <div class="field">
                        <label class="label">Default Reward Points per Task</label>
                        <div class="control">
                            <input class="input" type="number" id="chDefaultRewardPoints" min="0"
                                value="<?php echo (int)$chInitialSettings['default_reward_points']; ?>">
                        </div>
                        <p class="help">Applied when a new task is created without a custom value.</p>
                    </div>
                </div>
                <div class="column is-half">
                    <div class="field">
                        <label class="label">Toggles</label>
                        <div class="control">
                            <label class="checkbox mr-4">
                                <input type="checkbox" id="chRequireApproval"
                                    <?php echo $chInitialSettings['require_approval'] ? 'checked' : ''; ?>>
                                Require streamer approval before awarding points
                            </label>
                        </div>
                        <div class="control mt-2">
                            <label class="checkbox mr-4">
                                <input type="checkbox" id="chAllowUserTasks"
                                    <?php echo $chInitialSettings['allow_user_tasks'] ? 'checked' : ''; ?>>
                                Allow viewers to submit tasks
                            </label>
                        </div>
                        <div class="control mt-2">
                            <label class="checkbox">
                                <input type="checkbox" id="chTaskVisibleOverlay"
                                    <?php echo $chInitialSettings['task_visible_overlay'] ? 'checked' : ''; ?>>
                                Show tasks on overlay
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="field">
                <div class="control">
                    <button class="button is-link" id="chSaveSettingsBtn">Save Settings</button>
                </div>
            </div>
        </div>
        <!-- Dual-column task tables -->
        <div class="card-content">
            <div class="columns">
                <div class="column is-half">
                    <div class="level is-mobile mb-3">
                        <div class="level-left">
                            <h3 class="title is-6 mb-0">
                                <span class="icon mr-1"><i class="fas fa-list-check"></i></span>
                                Streamer Tasks
                            </h3>
                        </div>
                        <div class="level-right">
                            <button class="button is-primary is-small" id="chAddStreamerTaskBtn">
                                <span class="icon"><i class="fas fa-plus"></i></span>
                                <span>Add Task</span>
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="table is-fullwidth is-striped is-hoverable is-narrow">
                            <thead>
                                <tr><th>Task</th><th>Status</th><th>Pts</th><th>Actions</th></tr>
                            </thead>
                            <tbody id="chStreamerTaskBody">
                                <tr id="chStreamerEmpty">
                                    <td colspan="4" class="has-text-centered has-text-grey py-4">No tasks yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="column is-half">
                    <h3 class="title is-6 mb-3">
                        <span class="icon mr-1"><i class="fas fa-users"></i></span>
                        Viewer Tasks
                    </h3>
                    <div class="table-container">
                        <table class="table is-fullwidth is-striped is-hoverable is-narrow">
                            <thead>
                                <tr><th>User</th><th>Task</th><th>Status</th><th>Approval</th><th>Pts</th><th>Actions</th></tr>
                            </thead>
                            <tbody id="chUserTaskBody">
                                <tr id="chUserEmpty">
                                    <td colspan="6" class="has-text-centered has-text-grey py-4">No viewer tasks yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Add/Edit Streamer Task Modal -->
    <div class="modal" id="chStreamerTaskModal">
        <div class="modal-background"></div>
        <div class="modal-card">
            <header class="modal-card-head">
                <p class="modal-card-title" id="chStreamerTaskModalTitle">Add Streamer Task</p>
                <button class="delete" aria-label="close" id="chCloseStreamerTaskModal"></button>
            </header>
            <section class="modal-card-body">
                <input type="hidden" id="chEditStreamerTaskId" value="">
                <div class="field">
                    <label class="label">Task Title <span class="has-text-danger">*</span></label>
                    <div class="control">
                        <input class="input" type="text" id="chStreamerTaskTitle" placeholder="e.g. Beat the final boss">
                    </div>
                </div>
                <div class="field">
                    <label class="label">Description</label>
                    <div class="control">
                        <textarea class="textarea" id="chStreamerTaskDesc" rows="2" placeholder="Optional details..."></textarea>
                    </div>
                </div>
                <div class="columns">
                    <div class="column">
                        <div class="field">
                            <label class="label">Category</label>
                            <div class="control">
                                <input class="input" type="text" id="chStreamerTaskCategory" value="General">
                            </div>
                        </div>
                    </div>
                    <div class="column">
                        <div class="field">
                            <label class="label">Reward Points</label>
                            <div class="control">
                                <input class="input" type="number" id="chStreamerTaskPoints" min="0" value="50">
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <footer class="modal-card-foot">
                <button class="button is-success" id="chSaveStreamerTaskBtn">Save Task</button>
                <button class="button" id="chCancelStreamerTaskBtn">Cancel</button>
            </footer>
        </div>
    </div>
    <div class="toast-area" id="toastArea" aria-live="polite" role="status"></div>
</section>
<?php
$content = ob_get_clean();

ob_start();
?>
<script src="https://cdn.socket.io/4.8.3/socket.io.min.js"></script>
<script>
    (function () {
        const apiKey = <?php echo json_encode($api_key); ?>;
        const currentUsername = <?php echo json_encode($_SESSION['username']); ?>;
        const dashboardDebug = false;
        const overlayLink = <?php echo json_encode($overlayLinkWithCode); ?>;
        const currentUserId = <?php echo json_encode($_SESSION['twitchUserId'] ?? ''); ?>;
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
        const streamerTaskTableWrap = document.getElementById('streamerTaskTableWrap');
        const userTaskTableWrap = document.getElementById('userTaskTableWrap');
        const copyTasklistLinkBtn = document.getElementById('copyTasklistLinkBtn');
        const copyTasklistUserLinkBtn = document.getElementById('copyTasklistUserLinkBtn');
        const copyTasklistCombinedBtn = document.getElementById('copyTasklistCombinedBtn');
        const taskRewardEnabledInput = document.getElementById('taskRewardEnabled');
        const taskRewardPointsInput = document.getElementById('taskRewardPoints');
        const hasLegacyTaskUi = Boolean(taskInputTitle && taskInputPriority && addTaskBtn && streamerTaskTableWrap && userTaskTableWrap);
        const hasRewardInputs = Boolean(taskRewardEnabledInput && taskRewardPointsInput);
        let taskList = [];
        let userTaskList = [];
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
                    if (hasRewardInputs) {
                        taskRewardEnabledInput.checked = Number(initialSettings.reward_enabled || 0) === 1;
                        taskRewardPointsInput.value = initialSettings.reward_points_per_task || 10;
                    }
                    console.log('[Timer] Settings loaded from page initialization:', initialSettings);
                }
            } catch (error) {
                console.error('[Timer] Error loading settings:', error);
            }
        };
        // Save settings to database and sync live to overlay via WebSocket
        const saveSettingsToDatabase = async () => {
            try {
                const focus = safeNumberValue(focusLengthInput, 60);
                const micro = safeNumberValue(microBreakInput, 5);
                const recharge = safeNumberValue(breakLengthInput, 30);
                const rewardEnabled = hasRewardInputs && taskRewardEnabledInput.checked ? 1 : 0;
                const rewardPointsRaw = hasRewardInputs ? Number(taskRewardPointsInput.value) : 10;
                const rewardPoints = Number.isFinite(rewardPointsRaw) && rewardPointsRaw >= 0 ? Math.floor(rewardPointsRaw) : 10;
                const formData = new FormData();
                formData.append('action', 'save_settings');
                formData.append('focus_minutes', String(focus));
                formData.append('micro_break_minutes', String(micro));
                formData.append('recharge_break_minutes', String(recharge));
                formData.append('reward_enabled', String(rewardEnabled));
                formData.append('reward_points_per_task', String(rewardPoints));
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const result = await response.json();
                if (!result.success) {
                    throw new Error(result.error || 'Failed to save timer settings');
                }
                if (socket && socket.connected) {
                    socket.emit('SPECTER_SETTINGS_UPDATE', {
                        code: apiKey,
                        focus_minutes: focus,
                        micro_break_minutes: micro,
                        recharge_break_minutes: recharge,
                        reward_enabled: rewardEnabled,
                        reward_points_per_task: rewardPoints
                    });
                    console.log('[Timer] Settings saved to database and sent to overlay');
                } else {
                    console.warn('[Timer] Settings saved to database, but WebSocket not connected for live sync');
                }
                showToast('✓ Timer settings saved', 'success');
            } catch (error) {
                console.error('[Timer] Error saving settings:', error);
                showToast('⚠️ Error saving settings', 'danger');
            }
        };
        // Load tasks from database (PHP-injected on page load)
        const loadTasksFromDatabase = async () => {
            if (!hasLegacyTaskUi) {
                return;
            }
            try {
                const splitTasks = (tasks) => {
                    taskList = (tasks || []).filter(task => {
                        const taskOwner = String(task.username || currentUsername).toLowerCase();
                        return taskOwner === String(currentUsername).toLowerCase();
                    });
                    userTaskList = (tasks || []).filter(task => {
                        const taskOwner = String(task.username || '').toLowerCase();
                        return taskOwner && taskOwner !== String(currentUsername).toLowerCase();
                    });
                };

                if (initialTasks && initialTasks.length > 0) {
                    splitTasks(initialTasks);
                    renderTaskList();
                    console.log('[Tasks] Loaded from page initialization:', taskList.length, 'streamer tasks and', userTaskList.length, 'user tasks');
                }
                const formData = new FormData();
                formData.append('action', 'get_tasks');
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const result = await response.json();
                if (result.success && Array.isArray(result.data)) {
                    splitTasks(result.data);
                    renderTaskList();
                    console.log('[Tasks] Refreshed from database:', taskList.length, 'streamer tasks and', userTaskList.length, 'user tasks');
                }
            } catch (error) {
                console.error('[Tasks] Error loading tasks:', error);
            }
        };
        // Save tasks to database via in-page operation + WebSocket sync
        const saveTasksToDatabase = async () => {
            if (!hasLegacyTaskUi) {
                return;
            }
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
            if (!hasLegacyTaskUi) {
                return;
            }
            if (!taskList || taskList.length === 0) {
                streamerTaskTableWrap.innerHTML = `
                    <div style="padding: 20px; text-align: center; color: rgba(255, 255, 255, 0.5);">
                        <p>No streamer tasks yet. Add one to get started!</p>
                    </div>
                `;
            } else {
                streamerTaskTableWrap.innerHTML = `
                    <table class="table is-fullwidth is-striped is-hoverable">
                        <thead>
                            <tr>
                                <th style="width: 42px;">Done</th>
                                <th>Task</th>
                                <th style="width: 110px;">Priority</th>
                                <th style="width: 64px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${taskList.map((task, index) => `
                                <tr>
                                    <td>
                                        <div class="task-checkbox-custom ${task.completed ? 'checked' : ''}" data-task-index="${index}" style="flex-shrink: 0; width: 20px; height: 20px; min-width: 20px; border: 2px solid ${task.completed ? '#2ecc71' : 'rgba(255, 255, 255, 0.3)'}; border-radius: 4px; background: ${task.completed ? '#2ecc71' : 'transparent'}; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; color: #ffffff; text-shadow: ${task.completed ? '0 1px 2px rgba(0, 0, 0, 0.3)' : 'none'};">${task.completed ? '✓' : ''}</div>
                                    </td>
                                    <td style="color: #f8fbff; ${task.completed ? 'text-decoration: line-through; opacity: 0.6;' : ''}">${escapeHtml(task.title)}</td>
                                    <td><span style="padding: 2px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; background: ${getPriorityColor(task.priority, true)}; color: ${getPriorityColor(task.priority)};">${task.priority}</span></td>
                                    <td>
                                        <button type="button" class="button is-small is-danger is-light" data-task-index="${index}">
                                            <span class="icon is-small"><i class="fas fa-trash" aria-hidden="true"></i></span>
                                        </button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            }

            if (!userTaskList || userTaskList.length === 0) {
                userTaskTableWrap.innerHTML = `
                    <div style="padding: 20px; text-align: center; color: rgba(255, 255, 255, 0.5);">
                        <p>No user tasks yet.</p>
                    </div>
                `;
            } else {
                userTaskTableWrap.innerHTML = `
                    <table class="table is-fullwidth is-striped is-hoverable">
                        <thead>
                            <tr>
                                <th style="width: 42px;">Done</th>
                                <th>User</th>
                                <th>Task</th>
                                <th style="width: 110px;">Priority</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${userTaskList.map((task) => `
                                <tr>
                                    <td>${task.completed ? '✓' : ''}</td>
                                    <td>${escapeHtml(task.username || 'user')}</td>
                                    <td style="color: #f8fbff; ${task.completed ? 'text-decoration: line-through; opacity: 0.6;' : ''}">${escapeHtml(task.title)}</td>
                                    <td><span style="padding: 2px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; background: ${getPriorityColor(task.priority, true)}; color: ${getPriorityColor(task.priority)};">${task.priority}</span></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            }

            // Add event listeners
            streamerTaskTableWrap.querySelectorAll('.task-checkbox-custom').forEach(checkbox => {
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
            streamerTaskTableWrap.querySelectorAll('.button.is-danger').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const index = parseInt(e.currentTarget.dataset.taskIndex);
                    taskList.splice(index, 1);
                    renderTaskList();
                    emitTaskListUpdate();
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
            if (!hasLegacyTaskUi) {
                return;
            }
            if (socket && socket.connected) {
                console.log('[Dashboard] Emitting task list via WebSocket:', taskList.length, 'tasks');
                socket.emit('SPECTER_TASKLIST_UPDATE', {
                    code: apiKey,
                    tasks: taskList,
                    timestamp: Date.now(),
                    source: 'dashboard'
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
                if (!socket || !socket.connected) {
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
                loadTasksFromDatabase();
            });
            socket.on('SPECTER_TASKLIST', payload => {
                console.log('[Timer Dashboard] Received task list event:', payload);
                loadTasksFromDatabase();
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
        copyTasklistLinkBtn?.addEventListener('click', async () => {
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
        copyTasklistUserLinkBtn?.addEventListener('click', async () => {
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
        copyTasklistCombinedBtn?.addEventListener('click', async () => {
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
        addTaskBtn?.addEventListener('click', () => {
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
                user_id: currentUserId,
                createdAt: new Date().toISOString()
            });
            taskInputTitle.value = '';
            taskInputPriority.value = 'medium';
            renderTaskList();
            emitTaskListUpdate();
            showToast(`✓ Task added: "${title}"`, 'success');
        });
        // Enter key to add task
        taskInputTitle?.addEventListener('keypress', (e) => {
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
        taskRewardEnabledInput?.addEventListener('change', () => {
            saveSettingsToDatabase();
        });
        taskRewardPointsInput?.addEventListener('change', () => {
            const val = Number(taskRewardPointsInput.value);
            if (!Number.isFinite(val) || val < 0) {
                taskRewardPointsInput.value = 10;
                showToast('⚠️ Reward points must be 0 or more', 'danger');
            }
            saveSettingsToDatabase();
        });
    })();
</script>
<script>
/* ═══ Channel Task Manager ════════════════════════════════════════════════ */
(function () {
    'use strict';

    const chApiKey = <?php echo json_encode($api_key); ?>;
    const chRequireApprovalInit = <?php echo (int)$chInitialSettings['require_approval']; ?>;
    // ── WebSocket (shares the page-level socket if available, else own) ──────
    // Use a separate named socket for the task channel so REGISTER is distinct
    const chSocket = io('https://websocket.botofthespecter.com', { transports: ['websocket'] });
    chSocket.on('connect', () => {
        chSocket.emit('REGISTER', { code: chApiKey, channel: 'dashboard', name: 'Tasks' });
        chLoadTasks();
    });
    chSocket.on('TASK_CREATE',          (d) => { chAppendStreamerRow(d.task); chShowToast('Task created: ' + (d.task?.title || '')); });
    chSocket.on('TASK_UPDATE',          (d) => {
        const owner = String(d?.owner || d?.task?.owner || '').toLowerCase();
        if (owner === 'streamer' || (!owner && !d?.task?.user_name)) {
            chAppendStreamerRow(d.task);
            return;
        }
        chAppendUserRow(d.task);
    });
    chSocket.on('TASK_COMPLETE',        (d) => { chMarkStatus(d.task_id, d.owner || 'user', 'completed'); });
    chSocket.on('TASK_APPROVE',         (d) => { chUpdateApproval(d.task_id, 'approved'); });
    chSocket.on('TASK_REJECT',          (d) => { chUpdateApproval(d.task_id, 'rejected'); });
    chSocket.on('TASK_DELETE',          (d) => { chRemoveRow(d.task_id, d.owner || 'streamer'); });
    chSocket.on('TASK_LIST_SYNC',       (d) => {
        chRenderStreamer(d.streamer_tasks || []);
        chRenderUser(d.user_tasks || []);
    });
    chSocket.on('TASK_REWARD_CONFIRM',  (d) => {
        chShowToast(`✔ ${d.user_name} earned ${d.points_awarded} pts for a task! (total: ${d.new_total})`);
    });
    // ── Data ─────────────────────────────────────────────────────────────────
    function chLoadTasks() {
        chPost({ action: 'ch_get_tasks' }, (res) => {
            if (res.success) {
                chRenderStreamer(res.streamer_tasks || []);
                chRenderUser(res.user_tasks || []);
            }
        });
    }
    // ── Render ────────────────────────────────────────────────────────────────
    function chRenderStreamer(tasks) {
        const tbody = document.getElementById('chStreamerTaskBody');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!tasks.length) {
            tbody.innerHTML = '<tr id="chStreamerEmpty"><td colspan="4" class="has-text-centered has-text-grey py-4">No tasks yet.</td></tr>';
            return;
        }
        tasks.forEach(t => chAppendStreamerRow(t, false));
    }
    function chRenderUser(tasks) {
        const tbody = document.getElementById('chUserTaskBody');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!tasks.length) {
            tbody.innerHTML = '<tr id="chUserEmpty"><td colspan="6" class="has-text-centered has-text-grey py-4">No viewer tasks yet.</td></tr>';
            return;
        }
        tasks.forEach(t => chAppendUserRow(t, false));
    }
    function chAppendStreamerRow(task, emit = true) {
        if (!task) return;
        document.getElementById('chStreamerEmpty')?.remove();
        const tbody = document.getElementById('chStreamerTaskBody');
        if (!tbody) return;
        let row = document.getElementById('ch-st-' + task.id) || document.createElement('tr');
        row.id = 'ch-st-' + task.id;
        row.innerHTML = `
            <td><strong>${chEsc(task.title)}</strong><br><small class="has-text-grey">${chEsc(task.category || '')}</small></td>
            <td>${chStatusTag(task.status)}</td>
            <td>${task.reward_points ?? 0}</td>
            <td>
                <div class="buttons are-small">
                    <button class="button is-info is-light" onclick="chOpenEditTask(${task.id})">Edit</button>
                    ${task.status !== 'completed' ? `<button class="button is-success is-light" onclick="chCompleteStreamer(${task.id})">Done</button>` : ''}
                    <button class="button is-danger is-light" onclick="chDeleteStreamer(${task.id})">Delete</button>
                </div>
            </td>`;
        if (!document.getElementById('ch-st-' + task.id)) tbody.appendChild(row);
        if (emit) chSocket.emit('TASK_UPDATE', { channel_code: chApiKey, owner: 'streamer', task: { ...task, owner: 'streamer' } });
    }
    function chAppendUserRow(task, emit = true) {
        if (!task) return;
        document.getElementById('chUserEmpty')?.remove();
        const tbody = document.getElementById('chUserTaskBody');
        if (!tbody) return;
        let row = document.getElementById('ch-ut-' + task.id) || document.createElement('tr');
        row.id = 'ch-ut-' + task.id;
        const canApprove = task.approval_status === 'pending_approval';
        row.innerHTML = `
            <td>${chEsc(task.user_name)}</td>
            <td><strong>${chEsc(task.title)}</strong></td>
            <td>${chStatusTag(task.status)}</td>
            <td>${chApprovalTag(task.approval_status)}</td>
            <td>${task.reward_points ?? 0}</td>
            <td>
                <div class="buttons are-small">
                    ${task.status === 'active' ? `<button class="button is-success is-light" onclick="chCompleteUser(${task.id})">Done</button>` : ''}
                    ${canApprove ? `<button class="button is-link is-light" onclick="chApproveUser(${task.id})">Approve</button>` : ''}
                    ${canApprove ? `<button class="button is-warning is-light" onclick="chRejectUser(${task.id})">Reject</button>` : ''}
                </div>
            </td>`;
        if (!document.getElementById('ch-ut-' + task.id)) tbody.appendChild(row);
    }
    function chStatusTag(s) {
        const m = { active:'is-success', completed:'is-info', hidden:'is-dark', pending:'is-warning', rejected:'is-danger' };
        return `<span class="tag ${m[s]||'is-light'}">${s||'unknown'}</span>`;
    }
    function chApprovalTag(s) {
        const m = { auto:'is-light', pending_approval:'is-warning', approved:'is-success', rejected:'is-danger' };
        return `<span class="tag ${m[s]||'is-light'}">${s||'auto'}</span>`;
    }
    function chMarkStatus(id, owner, status) {
        const row = document.getElementById(`${owner==='streamer'?'ch-st-':'ch-ut-'}${id}`);
        if (!row) return;
        const statusCellIndex = owner === 'streamer' ? 2 : 3;
        const cell = row.querySelector(`td:nth-child(${statusCellIndex})`);
        if (cell) cell.innerHTML = chStatusTag(status);
    }
    function chUpdateApproval(id, status) {
        const row = document.getElementById('ch-ut-' + id);
        if (!row) return;
        const cell = row.querySelector('td:nth-child(4)');
        if (cell) cell.innerHTML = chApprovalTag(status);
    }
    function chRemoveRow(id, owner) {
        document.getElementById(`${owner==='streamer'?'ch-st-':'ch-ut-'}${id}`)?.remove();
    }
    // ── Actions ───────────────────────────────────────────────────────────────
    window.chOpenEditTask = function (id) {
        const row = document.getElementById('ch-st-' + id);
        if (!row) return;
        const cells = row.querySelectorAll('td');
        document.getElementById('chEditStreamerTaskId').value = id;
        document.getElementById('chStreamerTaskTitle').value    = cells[0].querySelector('strong')?.textContent || '';
        document.getElementById('chStreamerTaskDesc').value     = '';
        document.getElementById('chStreamerTaskCategory').value = cells[0].querySelector('small')?.textContent || 'General';
        document.getElementById('chStreamerTaskPoints').value   = cells[2].textContent.trim();
        document.getElementById('chStreamerTaskModalTitle').textContent = 'Edit Task';
        document.getElementById('chStreamerTaskModal').classList.add('is-active');
    };
    window.chDeleteStreamer = function (id) {
        if (!confirm('Delete this task?')) return;
        chPost({ action: 'ch_delete_streamer_task', id }, (res) => {
            if (res.success) {
                document.getElementById('ch-st-' + id)?.remove();
                chSocket.emit('TASK_DELETE', { channel_code: chApiKey, task_id: id, owner: 'streamer' });
                chShowToast('Task deleted.');
            }
        });
    };
    window.chCompleteStreamer = function (id) {
        chPost({ action: 'ch_complete_streamer_task', id }, (res) => {
            if (res.success) {
                chMarkStatus(id, 'streamer', 'completed');
                chSocket.emit('TASK_COMPLETE', { channel_code: chApiKey, task_id: id, owner: 'streamer' });
                chShowToast('Task completed.');
            }
        });
    };
    window.chCompleteUser = function (id) {
        chPost({ action: 'ch_complete_user_task', id }, (res) => {
            if (res.success && res.task) {
                const t = res.task;
                const requireApproval = document.getElementById('chRequireApproval')?.checked;
                chMarkStatus(id, 'user', 'completed');
                chSocket.emit('TASK_COMPLETE', {
                    channel_code: chApiKey, task_id: t.id, user_id: t.user_id,
                    user_name: t.user_name, title: t.title, reward_points: t.reward_points,
                    require_approval: requireApproval ? 1 : 0, owner: 'user',
                });
                chShowToast(`Task for ${t.user_name} marked complete.`);
            }
        });
    };
    window.chApproveUser = function (id) {
        chPost({ action: 'ch_approve_user_task', id }, (res) => {
            if (res.success && res.task) {
                const t = res.task;
                chUpdateApproval(id, 'approved');
                chSocket.emit('TASK_APPROVE', {
                    channel_code: chApiKey, task_id: t.id, user_id: t.user_id,
                    user_name: t.user_name, title: t.title, reward_points: t.reward_points,
                });
                chShowToast(`Approved task for ${t.user_name}.`);
            }
        });
    };
    window.chRejectUser = function (id) {
        chPost({ action: 'ch_reject_user_task', id }, (res) => {
            if (res.success) {
                chUpdateApproval(id, 'rejected');
                chSocket.emit('TASK_REJECT', { channel_code: chApiKey, task_id: id });
                chShowToast('Task rejected.');
            }
        });
    };
    // ── Settings ──────────────────────────────────────────────────────────────
    document.getElementById('chSaveSettingsBtn')?.addEventListener('click', () => {
        const payload = {
            action:               'ch_save_settings',
            require_approval:     document.getElementById('chRequireApproval')?.checked     ? 1 : 0,
            default_reward_points: document.getElementById('chDefaultRewardPoints')?.value  || 50,
            allow_user_tasks:     document.getElementById('chAllowUserTasks')?.checked      ? 1 : 0,
            task_visible_overlay: document.getElementById('chTaskVisibleOverlay')?.checked  ? 1 : 0,
        };
        chPost(payload, (res) => {
            if (res.success) {
                chSocket.emit('TASK_SETTINGS_UPDATE', { channel_code: chApiKey, settings: payload });
                chShowToast('Settings saved.');
            } else {
                chShowToast('Failed to save settings.', 'is-danger');
            }
        });
    });
    document.getElementById('chToggleSettingsBtn')?.addEventListener('click', () => {
        const panel = document.getElementById('chSettingsPanel');
        const chevron = document.getElementById('chSettingsChevron');
        const hidden = panel.style.display === 'none';
        panel.style.display = hidden ? '' : 'none';
        chevron.className = hidden ? 'fas fa-angle-down' : 'fas fa-angle-up';
    });
    // ── Add / Edit modal ──────────────────────────────────────────────────────
    document.getElementById('chAddStreamerTaskBtn')?.addEventListener('click', () => {
        document.getElementById('chEditStreamerTaskId').value = '';
        document.getElementById('chStreamerTaskTitle').value  = '';
        document.getElementById('chStreamerTaskDesc').value   = '';
        document.getElementById('chStreamerTaskCategory').value = 'General';
        document.getElementById('chStreamerTaskPoints').value = document.getElementById('chDefaultRewardPoints')?.value || 50;
        document.getElementById('chStreamerTaskModalTitle').textContent = 'Add Streamer Task';
        document.getElementById('chStreamerTaskModal').classList.add('is-active');
    });
    ['chCloseStreamerTaskModal', 'chCancelStreamerTaskBtn'].forEach(id => {
        document.getElementById(id)?.addEventListener('click', () => {
            document.getElementById('chStreamerTaskModal').classList.remove('is-active');
        });
    });
    document.getElementById('chSaveStreamerTaskBtn')?.addEventListener('click', () => {
        const id       = document.getElementById('chEditStreamerTaskId').value;
        const title    = document.getElementById('chStreamerTaskTitle').value.trim();
        const desc     = document.getElementById('chStreamerTaskDesc').value.trim();
        const category = document.getElementById('chStreamerTaskCategory').value.trim() || 'General';
        const points   = parseInt(document.getElementById('chStreamerTaskPoints').value) || 0;
        if (!title) { chShowToast('Title is required.', 'is-warning'); return; }
        const isEdit = !!id;
        const payload = isEdit
            ? { action: 'ch_update_streamer_task', id, title, description: desc, category, reward_points: points, status: 'active' }
            : { action: 'ch_create_streamer_task', title, description: desc, category, reward_points: points };
        chPost(payload, (res) => {
            if (res.success) {
                const taskObj = { id: res.id || parseInt(id), title, description: desc, category, reward_points: points, status: 'active', owner: 'streamer' };
                chAppendStreamerRow(taskObj);
                chSocket.emit(isEdit ? 'TASK_UPDATE' : 'TASK_CREATE', { channel_code: chApiKey, owner: 'streamer', task: taskObj });
                document.getElementById('chStreamerTaskModal').classList.remove('is-active');
                chShowToast(isEdit ? 'Task updated.' : 'Task created.');
            } else {
                chShowToast(res.error || 'Failed to save task.', 'is-danger');
            }
        });
    });
    // ── Utilities ─────────────────────────────────────────────────────────────
    function chPost(data, callback) {
        const fd = new FormData();
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        fetch('working-or-study.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(callback)
            .catch(e => chShowToast('Network error: ' + e.message, 'is-danger'));
    }
    function chEsc(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function chShowToast(msg, cls = 'is-success') {
        const area = document.getElementById('toastArea');
        if (!area) return;
        const el = document.createElement('div');
        el.className = `notification ${cls} toast-item`;
        el.style.cssText = 'margin-bottom:.5rem;animation:fadeIn .3s ease';
        el.textContent = msg;
        area.appendChild(el);
        setTimeout(() => el.remove(), 4000);
    }
})();
</script>
<?php
$scripts = ob_get_clean();

// Discard any stray output captured from includes before sending the page
ob_end_clean();
include 'layout.php';
?>