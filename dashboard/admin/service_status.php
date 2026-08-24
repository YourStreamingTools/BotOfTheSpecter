<?php
require_once '/var/www/lib/session_bootstrap.php';
session_write_close();
require_once __DIR__ . '/admin_access.php';
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";
require_once __DIR__ . '/../includes/websocket_control_client.php';
require_once __DIR__ . '/../includes/bots_api_client.php';

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

// Function to check if user is admin
function isAdmin() {
    global $conn;
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($is_admin);
    $result = $stmt->fetch();
    $stmt->close();
    return $result && $is_admin == 1;
}

// Check if user is authenticated and is admin
if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    echo json_encode(['error' => t('admin_service_status_error_auth_required')]);
    exit();
}

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => t('admin_service_status_error_admin_required')]);
    exit();
}

header('Content-Type: application/json');

// Function to get service status
function getServiceStatus($service_name, $ssh_host, $ssh_username, $ssh_password) {
    $status = 'Unknown';
    $pid = 'N/A';
    if (empty($ssh_host)) {
        return ['status' => $status, 'pid' => $pid];
    }
    try {
        $connection = SSHConnectionManager::getConnection($ssh_host, $ssh_username, $ssh_password);
        if ($connection) {
            $output = SSHConnectionManager::executeCommand($connection, "systemctl status $service_name");
            if ($output) {
                if (preg_match('/Active:\s*active\s*\(running\)/', $output)) {
                    $status = 'Running';
                } elseif (preg_match('/Active:\s*inactive/', $output)) {
                    $status = 'Stopped';
                } elseif (preg_match('/Active:\s*failed/', $output)) {
                    $status = 'Failed';
                }
                if (preg_match('/Main PID:\s*(\d+)/', $output, $matches)) {
                    $pid = $matches[1];
                }
            }
        }
    } catch (Exception $e) {
        $status = 'Error';
        $pid = 'N/A';
    }
    return ['status' => $status, 'pid' => $pid];
}

// Lightweight systemd unit probe (ActiveState/SubState/MainPID). Used when HTTP
// /health is down — e.g. bots-api mid-restart — so the admin card does not
// report Error/Failed for a unit that is actually running.
function getSystemdUnitStatus($service_name, $ssh_host, $ssh_username, $ssh_password) {
    $status = 'Unknown';
    $pid = 'N/A';
    if (empty($ssh_host) || $service_name === '') {
        return ['status' => $status, 'pid' => $pid];
    }
    try {
        $connection = SSHConnectionManager::getConnection($ssh_host, $ssh_username, $ssh_password);
        if (!$connection) {
            return ['status' => $status, 'pid' => $pid];
        }
        $output = SSHConnectionManager::executeCommandNoMarker(
            $connection,
            'systemctl show --property=ActiveState,SubState,MainPID --no-pager ' . $service_name
        );
        if ($output === false) {
            return ['status' => 'Error', 'pid' => $pid];
        }
        $props = [];
        foreach (preg_split("/\r\n|\n|\r/", (string)$output) as $line) {
            if (strpos($line, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            if ($k === 'ActiveState' || $k === 'SubState' || $k === 'MainPID') {
                $props[$k] = trim($v);
            }
        }
        $active = $props['ActiveState'] ?? '';
        $sub = $props['SubState'] ?? '';
        $pidRaw = $props['MainPID'] ?? '0';
        if (ctype_digit($pidRaw) && $pidRaw !== '0') {
            $pid = $pidRaw;
        }
        if ($active === 'active' && ($sub === 'running' || $sub === '')) {
            $status = 'Running';
        } elseif (in_array($active, ['activating'], true) || in_array($sub, ['auto-restart', 'start', 'start-pre', 'start-post'], true)) {
            $status = 'Starting';
        } elseif ($active === 'inactive' || $active === 'deactivating') {
            $status = 'Stopped';
        } elseif ($active === 'failed') {
            $status = 'Failed';
        } elseif ($active !== '') {
            $status = ucfirst($active);
        }
        return ['status' => $status, 'pid' => $pid];
    } catch (Exception $e) {
        return ['status' => 'Error', 'pid' => 'N/A'];
    }
}

// Get the requested service
$service = $_GET['service'] ?? '';

// Define service mappings
$serviceMap = [
    'discordbot' => [
        'service_name' => 'discordbot.service',
        'ssh_host' => $bots_ssh_host ?? '',
        'ssh_username' => $bots_ssh_username ?? '',
        'ssh_password' => $bots_ssh_password ?? ''
    ],
    'bots_api' => [
        'service_name' => 'bots-api.service',
        'ssh_host' => $bots_ssh_host ?? '',
        'ssh_username' => $bots_ssh_username ?? '',
        'ssh_password' => $bots_ssh_password ?? ''
    ],
    'bots_caddy' => [
        'service_name' => 'caddy.service',
        'ssh_host' => $bots_ssh_host ?? '',
        'ssh_username' => $bots_ssh_username ?? '',
        'ssh_password' => $bots_ssh_password ?? ''
    ],
    'fastapi' => [
        'service_name' => 'fastapi.service',
        'ssh_host' => $api_server_host ?? '',
        'ssh_username' => $api_server_username ?? '',
        'ssh_password' => $api_server_password ?? ''
    ],
    'api_caddy' => [
        'service_name' => 'caddy.service',
        'ssh_host' => $api_server_host ?? '',
        'ssh_username' => $api_server_username ?? '',
        'ssh_password' => $api_server_password ?? ''
    ],
    'websocket' => [
        'service_name' => 'websocket.service',
        'ssh_host' => $websocket_server_host ?? '',
        'ssh_username' => $websocket_server_username ?? '',
        'ssh_password' => $websocket_server_password ?? ''
    ],
    'mysql' => [
        'service_name' => 'mysql.service',
        'ssh_host' => $sql_server_host ?? '',
        'ssh_username' => $sql_server_username ?? '',
        'ssh_password' => $sql_server_password ?? ''
    ],
    'export_queue_worker' => [
        'service_name' => 'export_queue_worker.service',
        'ssh_host' => $bots_ssh_host ?? '',
        'ssh_username' => $bots_ssh_username ?? '',
        'ssh_password' => $bots_ssh_password ?? ''
    ],
    // Intentionally offline / retired — do not SSH; admin UI shows SHUTDOWN not Error
    'twitch_recorder' => [
        'service_name' => 'twitch-recorder.service',
        'ssh_host' => '',
        'ssh_username' => '',
        'ssh_password' => '',
        'fixed_status' => 'SHUTDOWN',
        'fixed_pid' => 'N/A',
    ],
    'web_caddy' => [
        'service_name' => 'caddy.service',
        'ssh_host' => $web_ssh_host ?? '',
        'ssh_username' => $web_ssh_username ?? '',
        'ssh_password' => $web_ssh_password ?? ''
    ]
];

if (!isset($serviceMap[$service])) {
    http_response_code(400);
    echo json_encode(['error' => t('admin_service_status_error_invalid_service')]);
    exit();
}

$config = $serviceMap[$service];
if (!empty($config['fixed_status'])) {
    echo json_encode([
        'status' => $config['fixed_status'],
        'pid' => $config['fixed_pid'] ?? 'N/A',
    ]);
    exit();
}
if ($service === 'bots_api') {
    $health = bots_api_health();
    if (!empty($health['ok'])) {
        $data = is_array($health['data'] ?? null) ? $health['data'] : [];
        $pid = $data['pid'] ?? null;
        if (is_numeric($pid) && (int)$pid > 0) {
            $pid = (string)(int)$pid;
        } else {
            $sys = getSystemdUnitStatus(
                $config['service_name'],
                $config['ssh_host'],
                $config['ssh_username'],
                $config['ssh_password']
            );
            $pid = (!empty($sys['pid']) && $sys['pid'] !== 'N/A') ? (string)$sys['pid'] : 'N/A';
        }
        echo json_encode(['status' => 'Running', 'pid' => $pid]);
        exit();
    }
    // /health is down during restart (process up, socket not bound yet) or a 502
    // from Caddy. That is not a unit failure — ask systemd.
    $sys = getSystemdUnitStatus(
        $config['service_name'],
        $config['ssh_host'],
        $config['ssh_username'],
        $config['ssh_password']
    );
    $payload = [
        'status' => $sys['status'] ?? 'Unknown',
        'pid' => $sys['pid'] ?? 'N/A',
    ];
    if (($payload['status'] === 'Error' || $payload['status'] === 'Unknown') && !empty($health['error'])) {
        $payload['error'] = $health['error'];
    }
    echo json_encode($payload);
    exit();
}
// WebSocket host: status via private control API (no SSH)
if ($service === 'websocket') {
    $ws = websocket_control_service_status('websocket');
    if (!empty($ws['ok']) && is_array($ws['data'] ?? null)) {
        $d = $ws['data'];
        echo json_encode([
            'status' => $d['status'] ?? 'Unknown',
            'pid' => isset($d['pid']) && $d['pid'] ? (string)$d['pid'] : 'N/A',
        ]);
    } else {
        echo json_encode([
            'status' => 'Error',
            'pid' => 'N/A',
            'error' => $ws['error'] ?? 'websocket control API failed',
        ]);
    }
    exit();
}
$result = getServiceStatus(
    $config['service_name'], 
    $config['ssh_host'], 
    $config['ssh_username'], 
    $config['ssh_password']
);

echo json_encode($result);
?>