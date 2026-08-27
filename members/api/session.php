<?php
require_once dirname(__DIR__) . '/includes/session.php';
members_session_start();

$loggedIn = members_logged_in();
$config = include '/var/www/config/main.php';
if (!is_array($config)) {
    $config = [];
}

json_out([
    'ok' => true,
    'logged_in' => $loggedIn,
    'username' => $loggedIn ? ($_SESSION['twitch_username'] ?? null) : null,
    'display_name' => $loggedIn ? ($_SESSION['display_name'] ?? $_SESSION['twitch_username'] ?? null) : null,
    'profile_image' => $loggedIn ? ($_SESSION['profile_image_url'] ?? $_SESSION['profile_image'] ?? null) : null,
    'twitch_user_id' => $loggedIn ? ($_SESSION['twitch_user_id'] ?? null) : null,
    'store_csrf' => $loggedIn ? members_store_csrf() : null,
    'dashboard_version' => $config['dashboardVersion'] ?? '',
]);
