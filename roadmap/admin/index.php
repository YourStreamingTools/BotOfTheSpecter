<?php
require_once dirname(__DIR__) . '/includes/session.php';
roadmap_session_start();
if (!roadmap_is_logged_in() || !roadmap_is_admin()) {
    $_SESSION['redirect_url'] = '/admin/index.php';
    header('Location: /login.php');
    exit;
}
require dirname(__DIR__) . '/includes/spa.php';
