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
    if (!@ssh2_auth_password($connection, $username, $password)) { return ['status' => 'ERROR', 'message' => 'SSH authentication failed']; }    // Execute systemctl status command to get detailed status
    $stream = ssh2_exec($connection, "systemctl status $serviceName");
    if (!$stream) { return ['status' => 'ERROR', 'message' => 'Failed to execute SSH command']; }
    stream_set_blocking($stream, true);
    $output = stream_get_contents($stream);
    fclose($stream);
    $latency = round((microtime(true) - $start) * 1000);
    // Parse the systemctl status output
    if (strpos($output, 'Active: active (running)') !== false) {
        return ['status' => 'OK', 'latency_ms' => $latency];
    } else if (strpos($output, 'Active: activating') !== false) {
        return ['status' => 'OK', 'latency_ms' => $latency];
    } else if (strpos($output, 'Active: inactive (dead)') !== false) {
        return ['status' => 'ERROR', 'message' => 'Service is stopped (inactive/dead)', 'latency_ms' => $latency];
    } else if (strpos($output, 'Active: failed') !== false) {
        return ['status' => 'ERROR', 'message' => 'Service has failed', 'latency_ms' => $latency];
    } else {
        // Extract the Active line for detailed error message
        $lines = explode("\n", $output);
        $activeLine = '';
        foreach ($lines as $line) {
            if (strpos(trim($line), 'Active:') === 0) {
                $activeLine = trim($line);
                break;
            }
        }
        return ['status' => 'ERROR', 'message' => $activeLine ?: 'Unknown service status', 'latency_ms' => $latency];
    }
}

// Map services to host/port
$serviceMap = [
    'web1' => [
        'name' => 'Web1 Service',
        'host' => 'botofthespecter.com',
        'port' => 443
    ],
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

// Discord bot global status/version
$discordVersionFilePath = '/var/www/logs/version/discord_version_control.txt';
$discordVersion = file_exists($discordVersionFilePath) ? trim(file_get_contents($discordVersionFilePath)) : '';

// Check status
if (isset($serviceMap[$service])) {
    $svc = $serviceMap[$service];
    // Check if this is an SSH service or regular port check
    if (isset($svc['type']) && $svc['type'] === 'ssh_service') {
        // SSH service check
        $result = checkSSHService($svc['ssh_host'], $svc['ssh_username'], $svc['ssh_password'], $svc['service_name']);
        // Special handling for Discord bot service - use Bots service latency when running
        $latency_to_show = $result['latency_ms'];
        if ($service === 'discordbot' && $result['status'] === 'OK') {
            // Get the Bots service latency to show instead of SSH latency
            $botsResult = checkPort($serviceMap['bots']['host'], $serviceMap['bots']['port']);
            if ($botsResult['status'] === 'OK') {
                $latency_to_show = $botsResult['latency_ms'];
            }
        }
        $serviceData = [
            'name' => $svc['name'],
            'status' => $result['status'],
            'latency_ms' => $result['status'] === 'OK' ? $latency_to_show : null,
            'host' => $svc['ssh_host'],
            'service' => $svc['service_name'],
            'timestamp' => date('c'),
            'checked_at' => time(),
            'message' => $result['status'] === 'OK' ? t('bot_running_normally') : $result['message'],
            'version' => $discordVersion
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
