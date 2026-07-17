<?php
// /var/www/lib/require_auth.php
// ----------------------------------------------------------------
// Auth gate for *.botofthespecter.com dashboard pages that need a
// logged-in user. Include AFTER session_bootstrap.php.
//
// If $_SESSION['access_token'] is missing, stash the current URI in
// $_SESSION['redirect_after_login'] and bounce to /login.php; the
// login flow honours that key to return the user to the page they
// were trying to reach instead of the dashboard.
// ----------------------------------------------------------------

if (defined('BOTS_REQUIRE_AUTH_INCLUDED')) {
    return;
}
define('BOTS_REQUIRE_AUTH_INCLUDED', true);

if (!isset($_SESSION['access_token'])) {
    $returnTo = $_SERVER['REQUEST_URI'] ?? '/';

    // session_bootstrap may have just destroyed the session row (Twitch
    // validate returned 401). After session_destroy, writes to $_SESSION
    // go nowhere - no storage row to persist them. Restart explicitly so
    // redirect_after_login actually survives to the next request.
    @session_unset();
    @session_destroy();
    session_start();

    // Same safety check login.php applies before honouring the key.
    if (is_string($returnTo) && strncmp($returnTo, '/', 1) === 0 && strncmp($returnTo, '//', 2) !== 0) {
        $_SESSION['redirect_after_login'] = $returnTo;
    }
    header('Location: /login.php');
    exit();
}
