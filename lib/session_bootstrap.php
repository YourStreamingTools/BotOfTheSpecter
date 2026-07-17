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

// ----------------------------------------------------------------
// Bot short-circuit. Crawlers don't honor cookies and never come back
// with one, so every page hit from them spawns a brand-new session id
// and writes a fresh empty row into web_sessions. Across all four
// *.botofthespecter.com subdomains that's measurable garbage.
//
// Detect the obvious crawler/preview-bot user agents and bail out
// BEFORE session_start so they never get a Set-Cookie at all. The
// bot still receives the page (we don't 403 them - losing SEO on
// home/support docs would be worse than the DB churn). They just
// don't get session storage.
//
// Pattern is intentionally broad - any false positive just means a
// "real user" with a botty UA loses session, which they can
// correct by using a normal browser. The trade is heavily in our
// favor at the database level.
// ----------------------------------------------------------------
$bots_ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
// Categories covered:
//   Search crawlers: googlebot, bingbot, slurp (yahoo), duckduckbot, baiduspider,
//                    yandexbot, sogou, exabot, seznambot, petalbot, applebot,
//                    googleother, google-inspectiontool
//   Preview bots:    facebookexternalhit, facebot, twitterbot, linkedinbot,
//                    slackbot, discordbot, telegrambot, whatsapp
//   SEO scrapers:    semrushbot, ahrefsbot, mj12bot, dotbot, bytespider
//   AI crawlers:     gptbot, chatgpt-user, claudebot, anthropic-ai, ccbot,
//                    perplexitybot, amazonbot
//   Uptime monitors: hetrixtools, uptimerobot, pingdom, statuscake, uptime,
//                    site24x7, betteruptime, monitorbacklinks, newrelicpinger
//   Generic:         crawl, spider, scrapy, python-requests, curl/, wget/
// All matched UAs short-circuit BEFORE session_start - no cookie, no
// session id generated, no DB row written. They still receive the page
// (we don't 403 - losing SEO/uptime visibility is worse than the churn).
if ($bots_ua !== '' && preg_match(
    '~(?:googlebot|bingbot|slurp|duckduckbot|baiduspider|yandexbot|sogou|exabot|'
    . 'facebot|facebookexternalhit|twitterbot|linkedinbot|slackbot|discordbot|'
    . 'telegrambot|whatsapp|applebot|petalbot|semrushbot|ahrefsbot|mj12bot|'
    . 'dotbot|seznambot|bytespider|gptbot|claudebot|anthropic-ai|ccbot|'
    . 'googleother|google-inspectiontool|amazonbot|chatgpt-user|perplexitybot|'
    . 'hetrixtools|uptimerobot|pingdom|statuscake|site24x7|betteruptime|'
    . 'monitorbacklinks|newrelicpinger|uptime\.com|uptimekuma|nodeping|'
    . 'crawl|spider|scrapy|python-requests|curl/|wget/)~i',
    $bots_ua
)) {
    // Don't start a session, don't set a cookie, don't write a row.
    // $_SESSION will be undefined for the rest of the request - code
    // that does isset()/empty()/$_SESSION[...] reads handles that fine
    // (PHP returns null for missing superglobal keys without warning).
    return;
}

require_once __DIR__ . '/web_session.php';
require_once '/var/www/config/database.php';

// ----------------------------------------------------------------
// Resolve DB credentials regardless of include scope.
//
// This bootstrap is sometimes require_once'd from *inside* a function
// (e.g. roadmap_session_start(), support_session_start()). Two cases:
//
//   A) database.php loads here for the first time inside that function
//      → $db_* exist in the function's local scope only.
//   B) database.php was already require_once'd at global scope earlier
//      in the request (e.g. get-activity.php) → the require_once above
//      is a no-op and $db_* only exist in global scope.
//
// Pull from global scope when local copies are missing, then promote to
// $GLOBALS so helpers like website_db() can always `global $db_*` and
// find something. The mysqli connection below reads from $GLOBALS so it
// works in both cases.
// ----------------------------------------------------------------
if (!isset($db_servername) || !isset($db_username) || !isset($db_password)) {
    global $db_servername, $db_username, $db_password;
}
if (isset($db_servername)) $GLOBALS['db_servername'] = $db_servername;
if (isset($db_username))   $GLOBALS['db_username']   = $db_username;
if (isset($db_password))   $GLOBALS['db_password']   = $db_password;

// 4-hour session lifetime to match Twitch access-token TTL (defined in web_session.php)
$BOTS_SESSION_LIFETIME = BOTS_SESSION_LIFETIME;

// ----------------------------------------------------------------
// Cookie config - must run before session_start().
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
$bots_session_db = new mysqli(
    $GLOBALS['db_servername'] ?? '',
    $GLOBALS['db_username']   ?? '',
    $GLOBALS['db_password']   ?? '',
    'website'
);
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
// here also persist back to web_sessions on the next write - harmless
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
//   ok          -> refresh twitch_expires_at + last_validated_at
//   invalid 401 -> try refresh_token; only destroy if refresh fails hard
//   transient   -> log + push validation forward 60s, KEEP the session.
//                  A flaky id.twitch.tv or egress blip must not log
//                  every active user out across all four apps.
//
// Twitch validate is the sole authority for "is this login still real?".
// The denormalized twitch_expires_at column is only a hint for when to
// re-check - it must never destroy a session on its own.
// ----------------------------------------------------------------
if (!empty($_SESSION['access_token'])) {
    $now           = time();
    $last_validate = (int)($_SESSION['last_validated_at'] ?? 0);
    $expires_at    = (int)($_SESSION['twitch_expires_at']  ?? 0);
    $needs_validate = ($last_validate === 0)
        || ($expires_at > 0 && $expires_at <= $now)
        || (($now - $last_validate) > 300);
    if ($needs_validate) {
        $result = bots_twitch_validate($_SESSION['access_token']);
        if (!empty($result['ok'])) {
            $payload = $result['payload'];
            $_SESSION['twitch_expires_at']  = $now + (int)($payload['expires_in'] ?? 0);
            $_SESSION['last_validated_at']  = $now;
        } elseif (($result['reason'] ?? '') === 'invalid') {
            // Twitch says access token is dead. Try refresh before logout.
            $kept = false;
            $rt   = (string)($_SESSION['refresh_token'] ?? '');
            if ($rt !== '') {
                $refresh = bots_twitch_refresh_token($rt, $bots_session_db);
                if (!empty($refresh['ok'])) {
                    $_SESSION['access_token']      = $refresh['access_token'];
                    $_SESSION['refresh_token']     = $refresh['refresh_token'];
                    $_SESSION['twitch_expires_at'] = $now + (int)$refresh['expires_in'];
                    $_SESSION['last_validated_at'] = $now;
                    // Keep users.access_token in step so bot/API and
                    // legacy lookups don't see a stale global token.
                    $twuid = (string)($_SESSION['twitchUserId']
                        ?? $_SESSION['twitch_user_id']
                        ?? '');
                    if ($twuid !== '' && $bots_session_db instanceof mysqli) {
                        $u = $bots_session_db->prepare(
                            'UPDATE users SET access_token = ?, refresh_token = ? WHERE twitch_user_id = ?'
                        );
                        if ($u) {
                            $newAccess  = $_SESSION['access_token'];
                            $newRefresh = $_SESSION['refresh_token'];
                            $u->bind_param('sss', $newAccess, $newRefresh, $twuid);
                            if (!$u->execute()) {
                                error_log('[session_bootstrap] users token sync after refresh failed: '
                                    . $u->error);
                            }
                            $u->close();
                        }
                    }
                    $kept = true;
                    error_log('[session_bootstrap] twitch validate 401, refreshed access token for sid='
                        . session_id());
                } elseif (($refresh['reason'] ?? '') === 'transient') {
                    // Refresh failed for operational reasons - keep session,
                    // retry soon. Do not bounce the user to SSO.
                    error_log('[session_bootstrap] token refresh transient http='
                        . ($refresh['http'] ?? '?')
                        . ' err=' . ($refresh['err'] ?? '')
                        . ' - keeping session');
                    $_SESSION['last_validated_at'] = $now - 240;
                    $kept = true;
                } else {
                    error_log('[session_bootstrap] token refresh invalid for sid='
                        . session_id()
                        . ' err=' . ($refresh['err'] ?? ''));
                }
            } else {
                error_log('[session_bootstrap] twitch validate 401, no refresh_token for sid='
                    . session_id());
            }
            if (!$kept) {
                // Access token invalid AND refresh unavailable/rejected.
                // Wipe the shared session so every *.botofthespecter.com
                // app bounces to SSO.
                error_log('[session_bootstrap] destroying session after failed validate+refresh sid='
                    . session_id());
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
            }
        } else {
            // Transient - keep the session, retry in ~60s.
            error_log('[session_bootstrap] twitch validate transient failure http='
                . ($result['http'] ?? '?')
                . ' err=' . ($result['err'] ?? '')
                . ' - keeping session');
            $_SESSION['last_validated_at'] = $now - 240; // retry in 60s instead of 300s
        }
    }
}
