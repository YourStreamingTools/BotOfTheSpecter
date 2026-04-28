<?php
// home/logout.php
// ----------------------------------------------------------------
// Ends the user's session everywhere.
// session_destroy() drives WebSessionHandler::destroy(), which DELETEs
// the row from web_sessions, so dashboard / support / members lose
// their cookie-shared identity on the very next page load.
// ----------------------------------------------------------------

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

header('Location: /');
exit;
