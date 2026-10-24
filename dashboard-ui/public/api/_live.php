<?php
// Bridge for the beta copies of the live dashboard endpoints (api/bot/*.php):
// beta session in place of /var/www/lib/session_bootstrap.php, plus the same
// 401 gate as /var/www/lib/require_auth_ajax.php. The copied endpoint code is
// otherwise the live code, using the live config and includes on web1.
require_once __DIR__ . '/_session.php';
beta_session_start();
if (!isset($_SESSION['access_token'])) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'session_expired' => true, 'message' => 'Session expired - please sign in again.']);
    exit();
}
define('BOTS_REQUIRE_AUTH_AJAX_INCLUDED', true);

// Twitch answered 401 for this user's token: the sign-in has expired, so end
// it the same way as a missing session (the page's guard sends them to sign in).
// While acting as another channel the token is that channel's, not yours, so a
// Twitch 401 must not end your sign-in: report it as a failed request instead.
function beta_twitch_401(): never
{
    while (ob_get_level() > 0) { @ob_end_clean(); }
    if (!empty($_SESSION['admin_act_as_active'])) {
        http_response_code(424);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'act_as_token_expired' => true, 'message' => "This channel's Twitch sign-in has expired, so Twitch data can't be loaded while acting as them."]);
        exit();
    }
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'session_expired' => true, 'message' => 'Session expired - please sign in again.']);
    exit();
}
