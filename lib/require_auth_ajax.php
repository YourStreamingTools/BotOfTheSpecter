<?php
// /var/www/lib/require_auth_ajax.php
// Auth gate for AJAX/JSON endpoints. Include AFTER session_bootstrap.php.
//
// When $_SESSION['access_token'] is missing, respond with HTTP 401
// and a small standard JSON body so the global window.fetch patch in
// dashboard.js can spot it and redirect the browser to /login.php
// instead of trying to render UNKNOWN/Error states from the response.

if (defined('BOTS_REQUIRE_AUTH_AJAX_INCLUDED')) {
    return;
}
define('BOTS_REQUIRE_AUTH_AJAX_INCLUDED', true);

if (!isset($_SESSION['access_token'])) {
    // Drop any buffered output so the JSON below is the entire body.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'success'         => false,
        'session_expired' => true,
        'redirect'        => '/login.php',
        'message'         => 'Session expired - please sign in again.',
    ]);
    exit();
}
