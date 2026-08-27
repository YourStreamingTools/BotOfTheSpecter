<?php
// Public session probe for the home React app (login CTAs + footer version).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once '/var/www/lib/session_bootstrap.php';
$config = include '/var/www/config/main.php';

$loggedIn = !empty($_SESSION['access_token']);

echo json_encode([
    'ok' => true,
    'logged_in' => $loggedIn,
    'username' => $loggedIn ? ($_SESSION['username'] ?? null) : null,
    'display_name' => $loggedIn ? ($_SESSION['display_name'] ?? $_SESSION['username'] ?? null) : null,
    'dashboard_version' => $config['dashboardVersion'] ?? '',
]);
