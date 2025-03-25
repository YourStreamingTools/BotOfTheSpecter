<?php
header('Content-Type: application/json');

$service = $_GET['service'] ?? '';

function pingServer($host, $port) {
    $starttime = microtime(true);
    $file = @fsockopen($host, $port, $errno, $errstr, 2);
    $stoptime = microtime(true);
    $status = 0;

    if (!$file) {
        $status = -1;  // Site is down
    } else {
        fclose($file);
        $status = ($stoptime - $starttime) * 1000;
        $status = floor($status);
    }
    return $status;
}

$status = 'OFF';

switch ($service) {
    case 'api':
        $pingStatus = pingServer('10.240.0.120', 443);
        $status = $pingStatus >= 0 ? 'OK' : 'OFF';
        break;
    case 'websocket':
        $pingStatus = pingServer('10.240.0.254', 443);
        $status = $pingStatus >= 0 ? 'OK' : 'OFF';
        break;
    case 'database':
        $pingStatus = pingServer('10.240.0.40', 3306);
        $status = $pingStatus >= 0 ? 'OK' : 'OFF';
        break;
    case 'streaming':
        $pingStatus = pingServer('10.240.0.211', 80);
        $status = $pingStatus >= 0 ? 'OK' : 'OFF';
        break;
}

echo json_encode(['status' => $status]);
?>
