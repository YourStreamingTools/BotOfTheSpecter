<?php
// /var/www/lib/session_bootstrap.php
// ----------------------------------------------------------------
// Single entry point every *.botofthespecter.com app includes before
// touching $_SESSION. Configures the cross-subdomain cookie, registers
// the WebSessionHandler so all four apps share one logical session
// row in website.web_sessions, then opportunistically validates the
// Twitch access_token against id.twitch.tv/oauth2/validate.
//
// Usage at the top of any page (home/dashboard/support/members):
//     require_once '/var/www/lib/session_bootstrap.php';
//     // $_SESSION is now live and scoped to .botofthespecter.com
// ----------------------------------------------------------------

if (defined('BOTS_SESSION_BOOTSTRAPPED')) {
    return;
}
define('BOTS_SESSION_BOOTSTRAPPED', true);

require_once __DIR__ . '/web_session.php';
require_once '/var/www/config/database.php';

// 4-hour session lifetime to match Twitch access-token TTL
$BOTS_SESSION_LIFETIME = 14400;

// ----------------------------------------------------------------
// Cookie config — must run before session_start().
// Domain '.botofthespecter.com' (leading dot) makes the cookie span
// home / dashboard / support / members.
// ----------------------------------------------------------------
ini_set('session.use_strict_mode',     '1');
ini_set('session.cookie_secure',       '1');
ini_set('session.cookie_httponly',     '1');
ini_set('session.cookie_samesite',     'Lax');
ini_set('session.cookie_domain',       '.botofthespecter.com');
ini_set('session.cookie_path',         '/');
ini_set('session.gc_maxlifetime',      (string)$BOTS_SESSION_LIFETIME);
ini_set('session.serialize_handler',   'php_serialize');
session_name('bots_session');

// ----------------------------------------------------------------
// Connect to website DB and register the custom save handler.
// The DB connection is held for the lifetime of the request; the
// session handler reuses it for read/write/destroy/gc.
// ----------------------------------------------------------------
$bots_session_db = new mysqli($db_servername, $db_username, $db_password, 'website');
if ($bots_session_db->connect_error) {
    error_log('[session_bootstrap] DB connect failed: ' . $bots_session_db->connect_error);
    // Fall back to default session handler so the page still loads with an empty session.
    session_start();
    return;
}
$bots_session_db->set_charset('utf8mb4');

$bots_session_handler = new WebSessionHandler($bots_session_db, $BOTS_SESSION_LIFETIME);
session_set_save_handler($bots_session_handler, true);
session_start();

// ----------------------------------------------------------------
// Cross-app session-key aliases.
// home/dashboard write camelCase 'twitchUserId' / 'username' / 'profile_image';
// support and members historically read the snake_case variants.
// Mirror them at read time so every app sees the keys it expects
// regardless of which login flow populated the row. New keys added
// here also persist back to web_sessions on the next write — harmless
// duplication, keeps the source-of-truth row complete.
// ----------------------------------------------------------------
$BOTS_SESSION_ALIASES = [
    'twitchUserId'  => 'twitch_user_id',
    'username'      => 'twitch_username',
    'profile_image' => 'profile_image_url',
];
foreach ($BOTS_SESSION_ALIASES as $primary => $alias) {
    if (isset($_SESSION[$primary]) && !isset($_SESSION[$alias])) {
        $_SESSION[$alias] = $_SESSION[$primary];
    } elseif (isset($_SESSION[$alias]) && !isset($_SESSION[$primary])) {
        $_SESSION[$primary] = $_SESSION[$alias];
    }
}

// ----------------------------------------------------------------
// Twitch token validation.
// Calls id.twitch.tv/oauth2/validate at most every 5 minutes per session
// (or sooner if our stored expires_at says we're already past).
// On success: refresh twitch_expires_at from the response's expires_in.
// On failure (401): destroy the session so the next page redirects to login.
// ----------------------------------------------------------------
if (!empty($_SESSION['access_token'])) {
    $now           = time();
    $last_validate = (int)($_SESSION['last_validated_at'] ?? 0);
    $expires_at    = (int)($_SESSION['twitch_expires_at']  ?? 0);
    $needs_validate = ($last_validate === 0)
        || ($expires_at > 0 && $expires_at <= $now)
        || (($now - $last_validate) > 300);
    if ($needs_validate) {
        $payload = bots_twitch_validate($_SESSION['access_token']);
        if ($payload === null) {
            // Token revoked / invalid — wipe the session row so consumers
            // see no auth on the next page load and bounce to SSO.
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
        } else {
            $_SESSION['twitch_expires_at']  = $now + (int)($payload['expires_in'] ?? 0);
            $_SESSION['last_validated_at']  = $now;
        }
    }
}
