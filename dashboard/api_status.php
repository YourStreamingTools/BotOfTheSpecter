<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
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
    if ($fp) {
        fclose($fp);
        return ['status' => 'OK', 'latency_ms' => $latency];
    } else {
        return ['status' => 'ERROR', 'message' => "Connection failed: $errstr ($errno)"];
    }
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
    $result = checkPort($svc['host'], $svc['port']);
    $serviceData = [
        'name' => $svc['name'],
        'status' => $result['status'],
        'latency_ms' => $result['status'] === 'OK' ? $result['latency_ms'] : null,
        'host' => $svc['host'],
        'port' => $svc['port'],
        'host_port' => $svc['host'] . ':' . $svc['port'],
        'timestamp' => date('c'), // ISO 8601 format
        'checked_at' => time(),
        // Use translation for messages
        'message' => $result['status'] === 'OK' ? t('bot_running_normally') : $result['message']
    ];
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
