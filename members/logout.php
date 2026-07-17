<?php
// members/logout.php
// Members now shares the .botofthespecter.com cookie + web_sessions
// row with home/dashboard/support, so session_destroy() drives
// WebSessionHandler::destroy() which DELETEs the row - a single
// logout signs the user out of every *.botofthespecter.com app at
// once. Single login, single logout, by design.

require_once '/var/www/lib/session_bootstrap.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();

header('Location: login.php?logout=success');
exit;
