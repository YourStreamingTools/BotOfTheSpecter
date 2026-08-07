<?php
/**
 * Public host metrics for this web server (parity with Python GET /health/metrics).
 * Deploy on every web host. Identity comes from config/web_identity.php.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/includes/host_metrics.php';

$serverName = 'web1';
$service = 'web';

$identityPath = is_file('/var/www/config/web_identity.php')
    ? '/var/www/config/web_identity.php'
    : (__DIR__ . '/../config/web_identity.php');
if (is_file($identityPath)) {
    $identity = include $identityPath;
    if (is_array($identity)) {
        if (!empty($identity['server_name'])) {
            $serverName = (string) $identity['server_name'];
        }
        if (!empty($identity['service'])) {
            $service = (string) $identity['service'];
        }
    }
}

$metrics = specter_collect_host_metrics($serverName, $service);
if (!is_array($metrics) || empty($metrics['ok'])) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'host metrics unavailable',
        'server_name' => $serverName,
        'service' => $service,
    ]);
    exit;
}

echo json_encode($metrics);
