<?php
require __DIR__ . '/_session.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') beta_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
beta_session_start();
if (session_status() === PHP_SESSION_ACTIVE) {
    beta_ws_delete();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
}
beta_json(200, ['ok' => true]);
