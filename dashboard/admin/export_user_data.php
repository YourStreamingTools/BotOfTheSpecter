<?php
require_once '/var/www/lib/session_bootstrap.php';
session_write_close();
header('Content-Type: application/json');
require_once "/var/www/config/db_connect.php";

// Load translations so user-facing JSON messages are localized.
if (!function_exists('t')) {
    $userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : 'EN';
    $i18nPath = __DIR__ . '/../lang/i18n.php';
    if (file_exists($i18nPath)) {
        include_once $i18nPath;
    }
    if (!function_exists('t')) {
        function t($key, $replacements = [])
        {
            return $key;
        }
    }
}

function export_admin_audit_ensure_table($dbConn) {
    static $checked = false;
    if ($checked) {
        return true;
    }
    if (!($dbConn instanceof mysqli)) {
        return false;
    }
    $sql = "CREATE TABLE IF NOT EXISTS admin_audit_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        actor_user_id INT NULL,
        actor_username VARCHAR(255) NOT NULL DEFAULT '',
        action VARCHAR(128) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'info',
        target_type VARCHAR(64) NULL,
        target_value VARCHAR(255) NULL,
        details_json LONGTEXT NULL,
        request_method VARCHAR(10) NOT NULL DEFAULT 'GET',
        request_path VARCHAR(255) NOT NULL DEFAULT '',
        ip_address VARCHAR(45) NOT NULL DEFAULT '',
        user_agent VARCHAR(255) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at),
        INDEX idx_actor_username (actor_username),
        INDEX idx_action (action),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $checked = ($dbConn->query($sql) === true);
    return $checked;
}

function export_admin_audit_log($dbConn, $action, $status = 'info', $details = [], $targetType = null, $targetValue = null) {
    if (!($dbConn instanceof mysqli)) {
        return false;
    }
    if (!export_admin_audit_ensure_table($dbConn)) {
        return false;
    }

    if (!is_array($details)) {
        $details = ['value' => $details];
    }
    $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($detailsJson === false) {
        $detailsJson = null;
    }

    $actorId = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
    $actorUsername = isset($_SESSION['username']) ? mb_substr((string) $_SESSION['username'], 0, 255) : '';
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? mb_substr(strtoupper((string) $_SERVER['REQUEST_METHOD']), 0, 10) : 'POST';
    $requestPath = isset($_SERVER['REQUEST_URI'])
        ? mb_substr((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH), 0, 255)
        : mb_substr((string) ($_SERVER['PHP_SELF'] ?? ''), 0, 255);
    $ipAddress = isset($_SERVER['HTTP_CF_CONNECTING_IP']) && $_SERVER['HTTP_CF_CONNECTING_IP'] !== ''
        ? $_SERVER['HTTP_CF_CONNECTING_IP']
        : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown');
    $ipAddress = mb_substr((string) $ipAddress, 0, 45);
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '';

    $action = mb_substr(trim((string) $action), 0, 128);
    if ($action === '') {
        $action = 'export_user_data';
    }
    $status = mb_substr(trim((string) $status), 0, 32);
    if ($status === '') {
        $status = 'info';
    }
    $targetType = $targetType !== null ? mb_substr((string) $targetType, 0, 64) : null;
    $targetValue = $targetValue !== null ? mb_substr((string) $targetValue, 0, 255) : null;

    $stmt = $dbConn->prepare("INSERT INTO admin_audit_log (actor_user_id, actor_username, action, status, target_type, target_value, details_json, request_method, request_path, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param(
        'issssssssss',
        $actorId,
        $actorUsername,
        $action,
        $status,
        $targetType,
        $targetValue,
        $detailsJson,
        $requestMethod,
        $requestPath,
        $ipAddress,
        $userAgent
    );
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// Auth: allow admin or the user requesting their own data
if (!isset($_SESSION['access_token'])) {
    http_response_code(403);
    export_admin_audit_log($conn, 'export_user_data', 'failed', ['error' => 'Not authenticated'], 'username', isset($_POST['username']) ? trim((string) $_POST['username']) : '');
    echo json_encode(['success' => false, 'msg' => t('admin_export_user_data_not_authenticated')]);
    exit();
}

$requestUsername = isset($_POST['username']) ? trim($_POST['username']) : '';

// Authorization: admin or matching username (case-insensitive)
$isAllowed = false;
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
    $isAllowed = true;
}
// Allow a user to request their own export by username match (CI)
if (!empty($requestUsername) && isset($_SESSION['username']) && strcasecmp($_SESSION['username'], $requestUsername) === 0) {
    $isAllowed = true;
}

if (!$isAllowed) {
    http_response_code(403);
    export_admin_audit_log($conn, 'export_user_data', 'failed', ['error' => 'Forbidden', 'requested_username' => $requestUsername], 'username', $requestUsername);
    echo json_encode(['success' => false, 'msg' => t('admin_export_user_data_forbidden')]);
    exit();
}

// SSH-only: start worker on bot server. Load SSH config if present.
@include_once '/var/www/config/ssh.php';

$finalUsername = '';
if (!empty($requestUsername)) {
    $finalUsername = $requestUsername;
} elseif (!empty($_SESSION['username'])) {
    $finalUsername = $_SESSION['username'];
}

if (empty($finalUsername)) {
    export_admin_audit_log($conn, 'export_user_data', 'failed', ['error' => 'Username missing'], 'username', '');
    echo json_encode(['success' => false, 'msg' => t('admin_export_user_data_username_missing')]);
    exit();
}

$escapedArgs = escapeshellarg($finalUsername);

// Cooldown: prevent repeated export requests (7 days) for non-admins.
$cooldownDir = '/var/www/data_export_requests';
$cooldownFile = $cooldownDir . '/' . preg_replace('/[^a-z0-9._-]/', '_', strtolower($finalUsername)) . '.json';
$cooldownSeconds = 7 * 24 * 3600; // 7 days
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    if (file_exists($cooldownFile)) {
        $data = json_decode(@file_get_contents($cooldownFile), true);
        $last = isset($data['last_requested_at']) ? intval($data['last_requested_at']) : 0;
        $now = time();
        if ($now - $last < $cooldownSeconds) {
            $remain = $cooldownSeconds - ($now - $last);
            $days = floor($remain / 86400);
            $hours = floor(($remain % 86400) / 3600);
            $minutes = floor(($remain % 3600) / 60);
            $parts = [];
            if ($days) $parts[] = t('admin_export_user_data_unit_days', [$days]);
            if ($hours) $parts[] = t('admin_export_user_data_unit_hours', [$hours]);
            if ($minutes) $parts[] = t('admin_export_user_data_unit_minutes', [$minutes]);
            $when = $parts ? implode(' ', $parts) : t('admin_export_user_data_short_while');
            export_admin_audit_log($conn, 'export_user_data', 'failed', ['error' => 'Cooldown active', 'remaining_seconds' => $remain, 'username' => $finalUsername], 'username', $finalUsername);
            echo json_encode(['success' => false, 'msg' => t('admin_export_user_data_cooldown_active', [$when])]);
            exit();
        }
    }
}

// Use SSHConnectionManager if configured
if (isset($bots_ssh_host) && !empty($bots_ssh_host) && class_exists('SSHConnectionManager')) {
    try {
        $sshConn = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
        if (!$sshConn) {
            export_admin_audit_log($conn, 'export_user_data', 'failed', ['error' => 'Could not establish SSH connection', 'username' => $finalUsername], 'username', $finalUsername);
            echo json_encode(['success' => false, 'msg' => t('admin_export_user_data_ssh_connect_failed')]);
            exit();
        }
        // Enqueue job on the bot server for sequential processing
        try {
            $job = [
                'username' => $finalUsername,
                'requested_at' => time(),
                'requested_by' => isset($_SESSION['username']) ? $_SESSION['username'] : 'web',
            ];
            $jobJson = json_encode($job);
            $rand = bin2hex(random_bytes(4));
            $remoteFile = "/home/botofthespecter/export_queue/job-" . time() . "-" . $rand . ".json";
            // create queue dir and write job via heredoc to avoid escaping issues
            $remoteCmd = "mkdir -p /home/botofthespecter/export_queue && cat > " . escapeshellarg($remoteFile) . " <<'JSON'\n" . $jobJson . "\nJSON\n";
            $res = SSHConnectionManager::executeCommand($sshConn, $remoteCmd, true);
            if ($res === false) {
                export_admin_audit_log($conn, 'export_user_data', 'failed', ['error' => 'Failed to enqueue remote export job', 'username' => $finalUsername], 'username', $finalUsername);
                echo json_encode(['success' => false, 'msg' => t('admin_export_user_data_enqueue_failed')]);
                exit();
            }
            // Record the request timestamp so users cannot re-request within the cooldown window.
            if (!is_dir($cooldownDir)) {
                @mkdir($cooldownDir, 0755, true);
            }
            @file_put_contents($cooldownFile, json_encode(['last_requested_at' => time()]));
        } catch (Exception $e) {
            export_admin_audit_log($conn, 'export_user_data', 'failed', ['error' => 'SSH error creating job', 'exception' => $e->getMessage(), 'username' => $finalUsername], 'username', $finalUsername);
            echo json_encode(['success' => false, 'msg' => t('admin_export_user_data_ssh_job_error', [$e->getMessage()])]);
            exit();
        }
    } catch (Exception $e) {
        export_admin_audit_log($conn, 'export_user_data', 'failed', ['error' => 'SSH connection error', 'exception' => $e->getMessage(), 'username' => $finalUsername], 'username', $finalUsername);
        echo json_encode(['success' => false, 'msg' => t('admin_export_user_data_ssh_connection_error', [$e->getMessage()])]);
        exit();
    }
} else {
    // SSH not configured - provide diagnostic info
    $diagnostic = [];
    if (!isset($bots_ssh_host) || empty($bots_ssh_host)) {
        $diagnostic[] = t('admin_export_user_data_diag_no_host');
    }
    if (!class_exists('SSHConnectionManager')) {
        $diagnostic[] = t('admin_export_user_data_diag_no_class');
    }
    $msg = t('admin_export_user_data_ssh_config_missing', [implode(', ', $diagnostic)]);
    export_admin_audit_log($conn, 'export_user_data', 'failed', ['error' => $msg, 'username' => $finalUsername], 'username', $finalUsername);
    echo json_encode(['success' => false, 'msg' => $msg]);
    exit();
}

export_admin_audit_log($conn, 'export_user_data', 'success', ['username' => $finalUsername, 'message' => 'Export started'], 'username', $finalUsername);
echo json_encode(['success' => true, 'msg' => t('admin_export_user_data_export_started')]);
exit();
