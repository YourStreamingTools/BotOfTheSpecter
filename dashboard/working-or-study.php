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
    $awardTaskPoints = function (int $taskId, array $task, bool $forceAward = false) use ($db): array {
        $result = [
            'awarded' => false,
            'points_awarded' => 0,
            'new_total' => null,
            'user_name' => $task['user_name'] ?? null,
            'user_id' => $task['user_id'] ?? null,
            'task_id' => $taskId
        ];
        $rewardPoints = max(0, intval($task['reward_points'] ?? 0));
        $userId = (string)($task['user_id'] ?? '');
        $userName = (string)($task['user_name'] ?? '');
        if ($taskId <= 0 || $rewardPoints <= 0 || $userId === '') {
            return $result;
        }
        $alreadyRewardedStmt = $db->prepare("SELECT id FROM task_reward_log WHERE user_task_id = ? LIMIT 1");
        if (!$alreadyRewardedStmt) {
            throw new Exception('Prepare failed: ' . $db->error);
        }
        $alreadyRewardedStmt->bind_param("i", $taskId);
        if (!$alreadyRewardedStmt->execute()) {
            throw new Exception('Execute failed: ' . $alreadyRewardedStmt->error);
        }
        $alreadyRewarded = $alreadyRewardedStmt->get_result();
        $alreadyExists = $alreadyRewarded && $alreadyRewarded->num_rows > 0;
        $alreadyRewardedStmt->close();
        if ($alreadyExists) {
            return $result;
        }
        if (!$forceAward) {
            $requireApproval = 0;
            $settingsStmt = $db->prepare("SELECT require_approval FROM task_settings LIMIT 1");
            if ($settingsStmt) {
                if ($settingsStmt->execute()) {
                    $settingsRow = $settingsStmt->get_result()->fetch_assoc();
                    if ($settingsRow) {
                        $requireApproval = !empty($settingsRow['require_approval']) ? 1 : 0;
                    }
                }
                $settingsStmt->close();
            }
            if ($requireApproval && (($task['approval_status'] ?? '') !== 'approved')) {
                return $result;
            }
        }
        $currentPoints = 0;
        $hasExistingPoints = false;
        $selectPointsStmt = $db->prepare("SELECT points FROM bot_points WHERE user_id = ? LIMIT 1");
        if (!$selectPointsStmt) {
            throw new Exception('Prepare failed: ' . $db->error);
        }
        $selectPointsStmt->bind_param("s", $userId);
        if (!$selectPointsStmt->execute()) {
            throw new Exception('Execute failed: ' . $selectPointsStmt->error);
        }
        $pointsRow = $selectPointsStmt->get_result()->fetch_assoc();
        $selectPointsStmt->close();
        if ($pointsRow) {
            $hasExistingPoints = true;
            $currentPoints = intval($pointsRow['points'] ?? 0);
        }
        $newTotal = $currentPoints + $rewardPoints;
        if ($hasExistingPoints) {
            $updatePointsStmt = $db->prepare("UPDATE bot_points SET points = ?, user_name = ? WHERE user_id = ?");
            if (!$updatePointsStmt) {
                throw new Exception('Prepare failed: ' . $db->error);
            }
            $updatePointsStmt->bind_param("iss", $newTotal, $userName, $userId);
            if (!$updatePointsStmt->execute()) {
                throw new Exception('Execute failed: ' . $updatePointsStmt->error);
            }
            $updatePointsStmt->close();
        } else {
            $insertPointsStmt = $db->prepare("INSERT INTO bot_points (user_id, user_name, points) VALUES (?, ?, ?)");
            if (!$insertPointsStmt) {
                throw new Exception('Prepare failed: ' . $db->error);
            }
            $insertPointsStmt->bind_param("ssi", $userId, $userName, $newTotal);
            if (!$insertPointsStmt->execute()) {
                throw new Exception('Execute failed: ' . $insertPointsStmt->error);
            }
            $insertPointsStmt->close();
        }
        $logStmt = $db->prepare("INSERT INTO task_reward_log (user_task_id, user_id, user_name, points_awarded) VALUES (?, ?, ?, ?)");
        if (!$logStmt) {
            throw new Exception('Prepare failed: ' . $db->error);
        }
        $logStmt->bind_param("issi", $taskId, $userId, $userName, $rewardPoints);
        if (!$logStmt->execute()) {
            throw new Exception('Execute failed: ' . $logStmt->error);
        }
        $logStmt->close();
        $result['awarded'] = true;
        $result['points_awarded'] = $rewardPoints;
        $result['new_total'] = $newTotal;
        return $result;
    };
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
        $db->begin_transaction();
        try {
            $stmt = $db->prepare("UPDATE user_tasks SET approval_status='approved' WHERE id=?");
            if (!$stmt) { throw new Exception('Prepare failed: ' . $db->error); }
            $stmt->bind_param("i", $id);
            $ok = $stmt->execute();
            $stmt->close();
            $rs = $db->prepare("SELECT id, user_id, user_name, title, reward_points, approval_status, status FROM user_tasks WHERE id=?");
            if (!$rs) { throw new Exception('Prepare failed: ' . $db->error); }
            $rs->bind_param("i", $id);
            if (!$rs->execute()) { throw new Exception('Execute failed: ' . $rs->error); }
            $task = $rs->get_result()->fetch_assoc();
            $rs->close();
            $awardResult = ['awarded' => false, 'points_awarded' => 0, 'new_total' => null];
            if ($ok && $task && (($task['status'] ?? '') === 'completed')) {
                $awardResult = $awardTaskPoints($id, $task, true);
            }
            $db->commit();
            echo json_encode(['success' => $ok, 'task' => $task, 'reward' => $awardResult]);
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
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
        $db->begin_transaction();
        try {
            $stmt = $db->prepare("UPDATE user_tasks SET status='completed', completed_at=NOW() WHERE id=?");
            if (!$stmt) { throw new Exception('Prepare failed: ' . $db->error); }
            $stmt->bind_param("i", $id);
            $ok = $stmt->execute();
            $stmt->close();
            $rs = $db->prepare("SELECT id, user_id, user_name, title, reward_points, approval_status, status FROM user_tasks WHERE id=?");
            if (!$rs) { throw new Exception('Prepare failed: ' . $db->error); }
            $rs->bind_param("i", $id);
            if (!$rs->execute()) { throw new Exception('Execute failed: ' . $rs->error); }
            $task = $rs->get_result()->fetch_assoc();
            $rs->close();
            $awardResult = ['awarded' => false, 'points_awarded' => 0, 'new_total' => null];
            if ($ok && $task) {
                $awardResult = $awardTaskPoints($id, $task);
            }
            $db->commit();
            echo json_encode(['success' => $ok, 'task' => $task, 'reward' => $awardResult]);
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
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
        const copyTasklistLinkBtn = document.getElementById('copyTasklistLinkBtn');
        const copyTasklistUserLinkBtn = document.getElementById('copyTasklistUserLinkBtn');
        const copyTasklistCombinedBtn = document.getElementById('copyTasklistCombinedBtn');
        const tasklistLinkStreamer = `https://overlay.botofthespecter.com/working-or-study.php?code=${encodeURIComponent(apiKey)}&tasklist&streamer=true`;
        const tasklistLinkUsers = `https://overlay.botofthespecter.com/working-or-study.php?code=${encodeURIComponent(apiKey)}&tasklist&streamer=false`;
        const tasklistLinkCombined = `https://overlay.botofthespecter.com/working-or-study.php?code=${encodeURIComponent(apiKey)}&tasklist`;
        // PHP-injected initial data
        const initialSettings = <?php echo json_encode($initialSettings); ?>;
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
        // Save settings to database and sync live to overlay via WebSocket
        const saveSettingsToDatabase = async () => {
            try {
                const focus = safeNumberValue(focusLengthInput, 60);
                const micro = safeNumberValue(microBreakInput, 5);
                const recharge = safeNumberValue(breakLengthInput, 30);
                const formData = new FormData();
                formData.append('action', 'save_settings');
                formData.append('focus_minutes', String(focus));
                formData.append('micro_break_minutes', String(micro));
                formData.append('recharge_break_minutes', String(recharge));
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
                        recharge_break_minutes: recharge
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
        const escapeHtml = (text) => {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
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
                if (payload && typeof payload === 'object') {
                    if (payload.timerRunning) {
                        timerState = 'running';
                    } else if (payload.timerPaused) {
                        timerState = 'paused';
                    } else {
                        timerState = 'stopped';
                    }
                    updateButtonStates();
                }
                updateLiveTimer(payload);
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
        // Initialize by loading from database
        loadSettingsFromDatabase();
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
                    { specter_event: 'SPECTER_PHASE', ...gatherDurations(), ...payload },
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
                    ${canApprove ? `<button class="button is-link is-light" onclick="chAwardUser(${task.id})">Award</button>` : ''}
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
                if (res.reward?.awarded) {
                    chSocket.emit('TASK_REWARD_CONFIRM', {
                        channel_code: chApiKey,
                        task_id: t.id,
                        user_id: t.user_id,
                        user_name: t.user_name,
                        points_awarded: res.reward.points_awarded,
                        new_total: res.reward.new_total,
                    });
                    chShowToast(`✔ ${t.user_name} earned ${res.reward.points_awarded} pts (total: ${res.reward.new_total})`);
                }
            }
        });
    };
    window.chAwardUser = function (id) {
        chPost({ action: 'ch_approve_user_task', id }, (res) => {
            if (res.success && res.task) {
                const t = res.task;
                chUpdateApproval(id, 'approved');
                chSocket.emit('TASK_APPROVE', {
                    channel_code: chApiKey, task_id: t.id, user_id: t.user_id,
                    user_name: t.user_name, title: t.title, reward_points: t.reward_points,
                });
                chShowToast(`Awarded task for ${t.user_name}.`);
                if (res.reward?.awarded) {
                    chSocket.emit('TASK_REWARD_CONFIRM', {
                        channel_code: chApiKey,
                        task_id: t.id,
                        user_id: t.user_id,
                        user_name: t.user_name,
                        points_awarded: res.reward.points_awarded,
                        new_total: res.reward.new_total,
                    });
                    chShowToast(`✔ ${t.user_name} earned ${res.reward.points_awarded} pts (total: ${res.reward.new_total})`);
                }
            }
        });
    };
    window.chApproveUser = window.chAwardUser;
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