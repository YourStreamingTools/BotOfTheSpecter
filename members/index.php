<?php
require_once '/var/www/lib/session_bootstrap.php';

// POST /{channel}/store stays on store.php (checkout). GET is the React shell.
$_storePath = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
$_storeParts = $_storePath === '' ? [] : explode('/', $_storePath);
if (count($_storeParts) >= 2 && strtolower($_storeParts[1]) === 'store') {
    $_GET['user'] = $_storeParts[0];
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        require __DIR__ . '/store.php';
        exit();
    }
}
unset($_storePath, $_storeParts);

if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: https://members.botofthespecter.com/login.php');
    exit();
}

require __DIR__ . '/includes/spa.php';
