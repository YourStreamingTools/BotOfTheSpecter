<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
include_once '/var/www/config/ssh.php';
ob_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Authentication required']);
    exit();
}

// Get the service to check
$service = $_GET['service'] ?? '';

// Helper function to check TCP port
function checkPort($host, $port, $timeout = 3) {
    $start = microtime(true);
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $latency = round((microtime(true) - $start) * 1000); // ms
    if ($fp) { fclose($fp); return ['status' => 'OK', 'latency_ms' => $latency];}
    else { return ['status' => 'ERROR', 'message' => "Connection failed: $errstr ($errno)"]; }
}

// Helper function to check service status via SSH
function checkSSHService($host, $username, $password, $serviceName, $timeout = 5) {
    $start = microtime(true);
    // Check if SSH2 extension is available
    if (!extension_loaded('ssh2')) { return ['status' => 'ERROR', 'message' => 'SSH2 PHP extension not available']; }
    $connection = @ssh2_connect($host, 22);
    if (!$connection) { return ['status' => 'ERROR', 'message' => 'SSH connection failed']; }
    if (!@ssh2_auth_password($connection, $username, $password)) { return ['status' => 'ERROR', 'message' => 'SSH authentication failed']; }
    // Execute systemctl status command
    $stream = ssh2_exec($connection, "systemctl is-active $serviceName");
    if (!$stream) { return ['status' => 'ERROR', 'message' => 'Failed to execute SSH command']; }
    stream_set_blocking($stream, true);
    $output = trim(stream_get_contents($stream));
    fclose($stream);
    $latency = round((microtime(true) - $start) * 1000);
    if ($output === 'active') { return ['status' => 'OK', 'latency_ms' => $latency]; }
    else { return ['status' => 'ERROR', 'message' => "Service status: $output", 'latency_ms' => $latency]; }
}

// Map services to host/port
$serviceMap = [
    'api' => [
        'name' => 'API Service',
        'host' => 'api.botofthespecter.com',
        'port' => 443
    ],
    'database' => [
        'name' => 'Database Service',
        'host' => 'sql.botofthespecter.com',
        'port' => 3306
    ],
    'websocket' => [
        'name' => 'Notification Service',
        'host' => 'websocket.botofthespecter.com',
        'port' => 443
    ],
    'bots' => [
        'name' => 'Bots Service',
        'host' => 'bots.botofthespecter.com',
        'port' => 22
    ],
    'discordbot' => [
        'name' => 'Discord Bot Service',
        'type' => 'ssh_service',
        'service_name' => 'discordbot',
        'ssh_host' => $bots_ssh_host,
        'ssh_username' => $bots_ssh_username,
        'ssh_password' => $bots_ssh_password
    ],
    'streamingService' => [
        'name' => 'AU-EAST-1 Streaming Service',
        'host' => 'au-east-1.botofthespecter.video',
        'port' => 1935
    ],
    'streamingServiceWest' => [
        'name' => 'US-WEST-1 Streaming Service',
        'host' => 'us-west-1.botofthespecter.video',
        'port' => 1935
    ],
    'streamingServiceEast' => [
        'name' => 'US-EAST-1 Streaming Service',
        'host' => 'us-east-1.botofthespecter.video',
        'port' => 1935
    ]
];

// Check status
if (isset($serviceMap[$service])) {
    $svc = $serviceMap[$service];
    // Check if this is an SSH service or regular port check
    if (isset($svc['type']) && $svc['type'] === 'ssh_service') {
        // SSH service check
        $result = checkSSHService($svc['ssh_host'], $svc['ssh_username'], $svc['ssh_password'], $svc['service_name']);
        $serviceData = [
            'name' => $svc['name'],
            'status' => $result['status'],
            'latency_ms' => $result['status'] === 'OK' ? $result['latency_ms'] : null,
            'host' => $svc['ssh_host'],
            'service' => $svc['service_name'],
            'timestamp' => date('c'),
            'checked_at' => time(),
            'message' => $result['status'] === 'OK' ? t('bot_running_normally') : $result['message']
        ];
    } else {
        // Regular port check
        $result = checkPort($svc['host'], $svc['port']);
        $serviceData = [
            'name' => $svc['name'],
            'status' => $result['status'],
            'latency_ms' => $result['status'] === 'OK' ? $result['latency_ms'] : null,
            'host' => $svc['host'],
            'port' => $svc['port'],
            'host_port' => $svc['host'] . ':' . $svc['port'],
            'timestamp' => date('c'),
            'checked_at' => time(),
            'message' => $result['status'] === 'OK' ? t('bot_running_normally') : $result['message']
        ];
    }
} elseif ($service === 'ping') {
    $serviceData = [
        'pong' => true,
        'timestamp' => date('c'),
        'checked_at' => time()
    ];
} else {
    $serviceData = [
        'error' => t('bot_status_unknown'),
        'timestamp' => date('c'),
        'checked_at' => time()
    ];
}

// Return data as JSON
ob_clean(); // Clear any accidental output
header('Content-Type: application/json');
echo json_encode($serviceData);
exit();
?>
