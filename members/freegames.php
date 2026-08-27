<?php
require_once '/var/www/lib/session_bootstrap.php';

if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: https://members.botofthespecter.com/login.php');
    exit();
}

require __DIR__ . '/includes/spa.php';
