<?php
// Public session probe for the support React app.
// Docs are public; tickets still gate on require_login in their own endpoints.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/session.php';
support_session_start();

$loggedIn = !empty($_SESSION['access_token']);
$config = include '/var/www/config/main.php';

json_out([
    'ok' => true,
    'logged_in' => $loggedIn,
    'is_staff' => $loggedIn && is_staff(),
    'is_registered' => $loggedIn && is_registered_user(),
    'username' => $loggedIn ? ($_SESSION['username'] ?? null) : null,
    'display_name' => $loggedIn ? ($_SESSION['display_name'] ?? $_SESSION['username'] ?? null) : null,
    'profile_image' => $loggedIn ? ($_SESSION['profile_image'] ?? null) : null,
    'csrf_token' => $loggedIn ? csrf_token() : null,
    'dashboard_version' => $config['dashboardVersion'] ?? '',
]);
