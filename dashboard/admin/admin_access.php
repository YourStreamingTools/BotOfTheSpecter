<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "/var/www/config/db_connect.php";

// If an admin has started an "Act As" session, restore the original
// admin credentials for any admin panel request so admin actions
// always execute under the admin's identity (prevent accidental
// acting-as during admin operations).
if (isset($_SESSION['admin_act_as_active']) && $_SESSION['admin_act_as_active'] === true
    && isset($_SESSION['admin_act_as_original']) && is_array($_SESSION['admin_act_as_original'])) {
    $orig = $_SESSION['admin_act_as_original'];
    if (!empty($orig['access_token'])) {
        $_SESSION['access_token'] = $orig['access_token'];
    }
    if (isset($orig['user_id'])) {
        $_SESSION['user_id'] = $orig['user_id'];
    }
    if (isset($orig['username'])) {
        $_SESSION['username'] = $orig['username'];
    }
    if (isset($orig['is_admin'])) {
        $_SESSION['is_admin'] = $orig['is_admin'];
    }
    if (isset($orig['beta_access'])) {
        $_SESSION['beta_access'] = $orig['beta_access'];
    }
    if (isset($orig['api_key'])) {
        $_SESSION['api_key'] = $orig['api_key'];
    }
}

function admin_access_is_json_request() {
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower((string) $_SERVER['HTTP_ACCEPT']) : '';
    $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string) $_SERVER['CONTENT_TYPE']) : '';
    $requestedWith = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) : '';
    return strpos($accept, 'application/json') !== false
        || strpos($contentType, 'application/json') !== false
        || $requestedWith === 'xmlhttprequest';
}

function admin_access_is_sse_request() {
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower((string) $_SERVER['HTTP_ACCEPT']) : '';
    return strpos($accept, 'text/event-stream') !== false;
}

function admin_access_deny($message = 'Access denied. Administrator access required.') {
    http_response_code(403);
    if (admin_access_is_sse_request()) {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        echo "event: error\n";
        echo 'data: ' . $message . "\n\n";
        echo "event: done\n";
        echo "data: {\"success\":false}\n\n";
        exit;
    }
    if (admin_access_is_json_request()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
    $accessMode = 'denied';
    $info = $message;
    include __DIR__ . '/../restricted.php';
    exit;
}

function admin_audit_is_sensitive_key($key) {
    if (!is_string($key) || $key === '') {
        return false;
    }
    $key = strtolower($key);
    $sensitiveKeys = [
        'password', 'pass', 'secret', 'token', 'access_token', 'refresh_token',
        'api_key', 'apikey', 'authorization', 'cookie', 'client_secret', 'oauth'
    ];
    foreach ($sensitiveKeys as $sensitiveKey) {
        if (strpos($key, $sensitiveKey) !== false) {
            return true;
        }
    }
    return false;
}

function admin_audit_sanitize_value($value, $key = null) {
    if ($key !== null && admin_audit_is_sensitive_key((string) $key)) {
        return '[REDACTED]';
    }

    if (is_array($value)) {
        $sanitized = [];
        foreach ($value as $childKey => $childValue) {
            $sanitized[$childKey] = admin_audit_sanitize_value($childValue, (string) $childKey);
        }
        return $sanitized;
    }

    if (is_object($value)) {
        $sanitized = [];
        foreach (get_object_vars($value) as $childKey => $childValue) {
            $sanitized[$childKey] = admin_audit_sanitize_value($childValue, (string) $childKey);
        }
        return $sanitized;
    }

    if (is_string($value)) {
        $value = trim($value);
        if (preg_match('/^Bearer\s+/i', $value)) {
            return 'Bearer [REDACTED]';
        }
        if (strlen($value) > 500) {
            return mb_substr($value, 0, 500) . '...';
        }
    }

    return $value;
}

function admin_audit_get_actor_context() {
    $actorId = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
    $actorUsername = isset($_SESSION['username']) ? trim((string) $_SESSION['username']) : '';
    $ipAddress = isset($_SERVER['HTTP_CF_CONNECTING_IP']) && $_SERVER['HTTP_CF_CONNECTING_IP'] !== ''
        ? $_SERVER['HTTP_CF_CONNECTING_IP']
        : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown');
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    $requestPath = isset($_SERVER['REQUEST_URI'])
        ? (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH)
        : (isset($_SERVER['PHP_SELF']) ? (string) $_SERVER['PHP_SELF'] : 'unknown');

    return [
        'actor_id' => $actorId,
        'actor_username' => $actorUsername,
        'ip_address' => mb_substr((string) $ipAddress, 0, 45),
        'user_agent' => $userAgent,
        'request_method' => mb_substr($requestMethod, 0, 10),
        'request_path' => mb_substr((string) $requestPath, 0, 255),
    ];
}

function admin_audit_ensure_table() {
    static $tableChecked = false;
    global $conn;

    if ($tableChecked) {
        return true;
    }

    if (!isset($conn) || !($conn instanceof mysqli)) {
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

    $tableChecked = ($conn->query($sql) === true);
    return $tableChecked;
}

function admin_audit_log($action, $status = 'info', $details = [], $targetType = null, $targetValue = null) {
    global $conn;

    if (!isset($conn) || !($conn instanceof mysqli)) {
        return false;
    }
    if (!admin_audit_ensure_table()) {
        return false;
    }

    $action = trim((string) $action);
    if ($action === '') {
        $action = 'unknown_action';
    }
    $status = trim((string) $status);
    if ($status === '') {
        $status = 'info';
    }

    if (!is_array($details)) {
        $details = ['value' => $details];
    }
    $detailsJson = json_encode(admin_audit_sanitize_value($details), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($detailsJson === false) {
        $detailsJson = null;
    }

    $context = admin_audit_get_actor_context();

    $stmt = $conn->prepare("INSERT INTO admin_audit_log (actor_user_id, actor_username, action, status, target_type, target_value, details_json, request_method, request_path, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }

    $action = mb_substr($action, 0, 128);
    $status = mb_substr($status, 0, 32);
    $targetType = $targetType !== null ? mb_substr((string) $targetType, 0, 64) : null;
    $targetValue = $targetValue !== null ? mb_substr((string) $targetValue, 0, 255) : null;
    $actorUsername = mb_substr((string) $context['actor_username'], 0, 255);

    $stmt->bind_param(
        'issssssssss',
        $context['actor_id'],
        $actorUsername,
        $action,
        $status,
        $targetType,
        $targetValue,
        $detailsJson,
        $context['request_method'],
        $context['request_path'],
        $context['ip_address'],
        $context['user_agent']
    );

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function admin_audit_auto_log_request() {
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    $scriptName = basename((string) ($_SERVER['PHP_SELF'] ?? ''));

    if ($method === 'GET' && $scriptName === 'logs.php') {
        $logViewerFetchKeys = ['admin_log_user', 'admin_system_log_type', 'admin_token_log_type', 'admin_audit_log'];
        foreach ($logViewerFetchKeys as $logKey) {
            if (isset($_GET[$logKey])) {
                return;
            }
        }
    }

    $postKeys = array_keys($_POST ?? []);
    $queryKeys = array_keys($_GET ?? []);
    $action = '';

    if ($method !== 'GET') {
        if (isset($_POST['action']) && $_POST['action'] !== '') {
            $action = 'post_' . preg_replace('/[^a-z0-9_\-.]/i', '', (string) $_POST['action']);
        } elseif (!empty($postKeys)) {
            $action = 'post_' . preg_replace('/[^a-z0-9_\-.]/i', '', (string) $postKeys[0]);
        } else {
            $action = 'post_request';
        }
    } else {
        $getActionCandidates = [
            'action',
            'ajax',
            'refresh_data',
            'get_running_bots',
            'get_user_clients',
            'load_token_cache',
            'service',
            'script',
            'admin_log_user',
            'admin_system_log_type',
            'admin_token_log_type',
            'admin_audit_log'
        ];

        foreach ($getActionCandidates as $candidate) {
            if (isset($_GET[$candidate])) {
                $value = is_scalar($_GET[$candidate]) ? (string) $_GET[$candidate] : '1';
                $value = preg_replace('/[^a-z0-9_\-.]/i', '', $value);
                if ($value === '') {
                    $value = '1';
                }
                $action = 'get_' . $candidate . '_' . mb_substr($value, 0, 64);
                break;
            }
        }

        if ($action === '') {
            return;
        }
    }

    $details = [
        'post_keys' => $postKeys,
        'query_keys' => $queryKeys,
    ];
    if (isset($_POST['username'])) {
        $details['username'] = (string) $_POST['username'];
    }
    if (isset($_POST['service'])) {
        $details['service'] = (string) $_POST['service'];
    }
    if (isset($_GET['ajax'])) {
        $details['ajax'] = (string) $_GET['ajax'];
    }
    if (isset($_GET['service'])) {
        $details['service'] = (string) $_GET['service'];
    }
    if (isset($_GET['script'])) {
        $details['script'] = (string) $_GET['script'];
    }

    admin_audit_log($action, 'request', $details, 'page', $scriptName !== '' ? $scriptName : 'unknown');
}

if (!isset($_SESSION['access_token']) || empty($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: ../login.php');
    exit;
}

$access_token = $_SESSION['access_token'];
$adminStmt = $conn->prepare("SELECT id, username, is_admin FROM users WHERE access_token = ? LIMIT 1");

if (!$adminStmt) {
    admin_access_deny('Access denied. Unable to validate account permissions.');
}

$adminStmt->bind_param('s', $access_token);
$adminStmt->execute();
$adminResult = $adminStmt->get_result();
$adminRow = $adminResult ? $adminResult->fetch_assoc() : null;
$adminStmt->close();

if (!$adminRow) {
    header('Location: ../login.php');
    exit;
}

$is_admin = ((int) ($adminRow['is_admin'] ?? 0) === 1);
$_SESSION['user_id'] = (int) ($adminRow['id'] ?? 0);
$_SESSION['username'] = $adminRow['username'] ?? ($_SESSION['username'] ?? '');
$_SESSION['is_admin'] = $is_admin;

if (!$is_admin) {
    admin_access_deny('Access denied. This section is restricted to administrators only.');
}

if (!defined('ADMIN_AUDIT_REQUEST_LOGGED')) {
    define('ADMIN_AUDIT_REQUEST_LOGGED', true);
    admin_audit_auto_log_request();
}

// Signal to userdata.php that we are in the admin panel - act-as should not apply here.
if (!defined('ADMIN_PANEL_CONTEXT')) {
    define('ADMIN_PANEL_CONTEXT', true);
}
?>