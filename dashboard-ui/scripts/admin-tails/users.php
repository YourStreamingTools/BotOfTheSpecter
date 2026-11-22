<?php
// GET ?reveal=<id>&field=email|api_key: one value, after the page's confirm (audited).
// GET ?act_as_user=<id>: Act As in the beta dashboard. Mints a single-use
// handoff_tokens row (target 'beta-act-as', 5 min, no tokens stored) and sends
// the browser to beta.dashboard /api/act_as.php, which checks the same admin is
// signed in there. GET: the page strings and the current admin flags.
if (isset($_GET['reveal'])) {
    header('Content-Type: application/json');
    $id = (int) $_GET['reveal'];
    $field = ($_GET['field'] ?? '') === 'email' ? 'email' : 'api_key';
    $st = $conn->prepare("SELECT username, $field AS v FROM users WHERE id = ? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    admin_audit_log('admin_user_reveal_' . $field, $r ? 'info' : 'not_found', ['user_id' => $id], 'user', (string) ($r['username'] ?? $id));
    echo json_encode($r ? ['success' => true, 'value' => (string) $r['v']] : ['success' => false, 'msg' => t('admin_users_msg_user_not_found')]);
    exit;
}
if (isset($_GET['act_as_user'])) {
    $tid = (int) $_GET['act_as_user'];
    $back = function (string $s) { header('Cache-Control: no-store'); header('Location: /users?act_as=' . $s); exit; };
    if ($tid <= 0) $back('invalid');
    if ($tid === $currentAdminUserId) $back('invalid');
    $st = $conn->prepare('SELECT id, username FROM users WHERE id = ? LIMIT 1');
    $st->bind_param('i', $tid);
    $st->execute();
    $target = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$target) { admin_audit_log('act_as_user', 'warning', ['reason' => 'target_not_found', 'target_user_id' => $tid, 'surface' => 'beta-dashboard'], 'user_id', (string) $tid); $back('not_found'); }
    $db = badm_ws_db();
    $token = bin2hex(random_bytes(32));
    $ins = $db ? $db->prepare("INSERT INTO handoff_tokens (token, twitch_user_id, username, display_name, access_token, refresh_token, profile_image, is_admin, target, expires_at, used) VALUES (?, ?, ?, '', '', '', '', 1, 'beta-act-as', DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0)") : null;
    if (!$ins) { admin_audit_log('act_as_user', 'error', ['reason' => 'handoff_failed', 'target_user_id' => $tid, 'surface' => 'beta-dashboard'], 'user', (string) $target['username']); $back('error'); }
    $actorTid = (string) ($_SESSION['twitchUserId'] ?? '');
    $tidStr = (string) $tid;
    $ins->bind_param('sss', $token, $actorTid, $tidStr);
    $ok = $ins->execute();
    $ins->close();
    if (!$ok) $back('error');
    admin_audit_log('act_as_user_handoff', 'info', ['target_user_id' => $tid, 'target_username' => $target['username'], 'surface' => 'beta-dashboard'], 'user', (string) $target['username']);
    header('Cache-Control: no-store');
    header('Location: https://beta.dashboard.botofthespecter.com/api/act_as.php?handoff=' . $token);
    exit;
}
// GET ?watched_limit=<id>: how many other Twitch channels the user may record into
// their own storage (website.watched_record_limits, default 3). POST
// watched_limit_action=set (max_channels 0-50) or reset (back to the default).
if (isset($_GET['watched_limit']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['watched_limit_action']))) {
    header('Content-Type: application/json');
    $wlDefault = 3;
    $wlId = (int) ($_GET['watched_limit'] ?? ($_POST['user_id'] ?? 0));
    $wlRead = function (int $id) use ($conn, $wlDefault): array {
        $st = $conn->prepare('SELECT max_channels FROM watched_record_limits WHERE user_id = ? LIMIT 1');
        $st->bind_param('i', $id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        $st = $conn->prepare('SELECT COUNT(*) AS n FROM watched_record_channels WHERE user_id = ?');
        $st->bind_param('i', $id);
        $st->execute();
        $n = (int) ($st->get_result()->fetch_assoc()['n'] ?? 0);
        $st->close();
        return ['success' => true, 'limit' => $row ? (int) $row['max_channels'] : $wlDefault, 'default' => $wlDefault, 'custom' => (bool) $row, 'count' => $n];
    };
    try {
        $st = $conn->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
        $st->bind_param('i', $wlId);
        $st->execute();
        $wlUser = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$wlUser) { echo json_encode(['success' => false, 'msg' => t('admin_users_msg_user_not_found')]); exit; }
        if (isset($_GET['watched_limit'])) { echo json_encode($wlRead($wlId)); exit; }
        $wlAction = (string) $_POST['watched_limit_action'];
        if ($wlAction === 'reset') {
            $st = $conn->prepare('DELETE FROM watched_record_limits WHERE user_id = ?');
            $st->bind_param('i', $wlId);
        } elseif ($wlAction === 'set' && preg_match('/^\d{1,2}$/', (string) ($_POST['max_channels'] ?? '')) && (int) $_POST['max_channels'] <= 50) {
            $wlMax = (int) $_POST['max_channels'];
            $st = $conn->prepare('INSERT INTO watched_record_limits (user_id, max_channels, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE max_channels = VALUES(max_channels), updated_by = VALUES(updated_by)');
            $st->bind_param('iii', $wlId, $wlMax, $currentAdminUserId);
        } else { echo json_encode(['success' => false, 'msg' => 'Enter a number from 0 to 50.']); exit; }
        $st->execute();
        $st->close();
        admin_audit_log('admin_user_watched_limit', 'info', ['user_id' => $wlId, 'action' => $wlAction, 'max_channels' => $wlMax ?? null], 'user', (string) $wlUser['username']);
        echo json_encode($wlRead($wlId));
    } catch (mysqli_sql_exception $e) {
        echo json_encode(['success' => false, 'msg' => 'Recording other channels is not set up yet (the website migration has not been run).']);
    }
    exit;
}
header('Content-Type: application/json');
echo json_encode(['success' => true, 'current_admin_id' => $currentAdminUserId, 'is_super_admin' => $currentAdminIsSuperAdmin, 'L' => badm_lang('admin_users_'), 'title' => t('admin_user_management_title')], JSON_UNESCAPED_UNICODE);
exit;
