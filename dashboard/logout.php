<?php
// dashboard/logout.php
// session_destroy() drives WebSessionHandler::destroy() which DELETEs the
// shared web_sessions row, so signing out here also signs the user out of
// home / support / members. Single login = single logout.

require_once '/var/www/lib/session_bootstrap.php';

$_SESSION = array();

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();

header('Location: https://botofthespecter.com/');
exit;
?>