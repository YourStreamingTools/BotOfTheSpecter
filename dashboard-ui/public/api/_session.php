<?php
// Beta dashboard session: host-only httpOnly cookie, separate from the live
// dashboard's .botofthespecter.com session so the two never collide.
declare(strict_types=1);

function beta_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('SPECTER_BETA');
    // 4 hours from last activity, like the old dashboard (web_sessions rows
    // are purged by last_seen) and the Twitch token TTL.
    $life = 4 * 60 * 60;
    session_set_cookie_params([
        'lifetime' => $life,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string) $life);
    ini_set('session.use_strict_mode', '1');
    // Beta-only path, outside Debian's phpsessionclean sweep (which uses
    // php.ini's 24 minutes). A cron on web1 deletes files idle past 4 hours
    // every 15 minutes; PHP's own gc is a backup.
    $dir = '/var/lib/php/sessions-beta';
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    session_start();
    // SSO Active Sessions: keep this login's 'beta:' row in website.web_sessions
    // current, and end the beta session when the row was removed.
    if (!beta_ws_check()) return;
    // PHP only sends the cookie when a session is created, so refresh it on
    // each request to keep the browser's 4 hours sliding with the file's.
    if (!headers_sent() && session_id() !== '') {
        setcookie(session_name(), session_id(), ['expires' => time() + $life, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    }
}

// Own connection to the website DB, same credential lookup as
// /var/www/lib/session_bootstrap.php (database.php may already be loaded at
// global scope). Never touches the endpoints' own $conn.
function beta_ws_db(): ?mysqli
{
    static $db = null, $tried = false;
    if ($tried) return $db;
    $tried = true;
    require_once '/var/www/config/database.php';
    if (!isset($db_servername) || !isset($db_username) || !isset($db_password)) {
        global $db_servername, $db_username, $db_password;
    }
    if (isset($db_servername)) $GLOBALS['db_servername'] = $db_servername;
    if (isset($db_username)) $GLOBALS['db_username'] = $db_username;
    if (isset($db_password)) $GLOBALS['db_password'] = $db_password;
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = @new mysqli($GLOBALS['db_servername'] ?? '', $GLOBALS['db_username'] ?? '', $GLOBALS['db_password'] ?? '', 'website');
    if ($m->connect_error) {
        error_log('[beta session] DB connect failed: ' . $m->connect_error);
        return null;
    }
    $m->set_charset('utf8mb4');
    $db = $m;
    return $db;
}

function beta_ws_id(?string $sid = null): string
{
    return 'beta:' . ($sid ?? session_id());
}

// Insert or refresh this session's row (login and legacy sessions).
function beta_ws_register(): bool
{
    $db = beta_ws_db();
    if (!$db || empty($_SESSION['twitchUserId'])) return false;
    $id = beta_ws_id();
    $uid = (string) $_SESSION['twitchUserId'];
    $user = (string) ($_SESSION['username'] ?? '');
    $name = (string) ($_SESSION['display_name'] ?? '');
    $img = (string) ($_SESSION['profile_image'] ?? '');
    $admin = (int) ($_SESSION['is_admin'] ?? 0);
    $at = (string) ($_SESSION['access_token'] ?? '');
    $rt = (string) ($_SESSION['refresh_token'] ?? '');
    $exp = !empty($_SESSION['twitch_expires_at']) ? date('Y-m-d H:i:s', (int) $_SESSION['twitch_expires_at']) : null;
    $data = '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
    $st = $db->prepare(
        "INSERT INTO web_sessions
            (session_id, twitch_user_id, username, display_name, profile_image,
             is_admin, access_token, refresh_token, twitch_expires_at,
             data, ip, user_agent, last_seen_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
            twitch_user_id = VALUES(twitch_user_id), username = VALUES(username),
            display_name = VALUES(display_name), profile_image = VALUES(profile_image),
            is_admin = VALUES(is_admin), access_token = VALUES(access_token),
            refresh_token = VALUES(refresh_token), twitch_expires_at = VALUES(twitch_expires_at),
            ip = VALUES(ip), user_agent = VALUES(user_agent), last_seen_at = CURRENT_TIMESTAMP"
    );
    if (!$st) {
        error_log('[beta session] register prepare failed: ' . $db->error);
        return false;
    }
    $st->bind_param('sssssissssss', $id, $uid, $user, $name, $img, $admin, $at, $rt, $exp, $data, $ip, $ua);
    $ok = $st->execute();
    if (!$ok) error_log('[beta session] register failed: ' . $st->error);
    $st->close();
    if ($ok) $_SESSION['ws_row'] = 1;
    return $ok;
}

function beta_ws_delete(?string $sid = null): void
{
    $db = beta_ws_db();
    if (!$db) return;
    $id = beta_ws_id($sid);
    $st = $db->prepare('DELETE FROM web_sessions WHERE session_id = ?');
    if (!$st) return;
    $st->bind_param('s', $id);
    $st->execute();
    $st->close();
}

// Returns false when the session was revoked (and has been destroyed).
// A DB outage keeps the session (logged), like the live handler's fallback.
function beta_ws_check(): bool
{
    if (empty($_SESSION['access_token']) || empty($_SESSION['twitchUserId'])) return true;
    $db = beta_ws_db();
    if (!$db) return true;
    $id = beta_ws_id();
    $uid = (string) $_SESSION['twitchUserId'];
    $life = 4 * 60 * 60;
    $st = $db->prepare('SELECT TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) FROM web_sessions WHERE session_id = ? AND twitch_user_id = ? AND last_seen_at > NOW() - INTERVAL ? SECOND LIMIT 1');
    if (!$st) {
        error_log('[beta session] check prepare failed: ' . $db->error);
        return true;
    }
    $st->bind_param('ssi', $id, $uid, $life);
    $st->execute();
    $st->bind_result($age);
    $found = $st->fetch();
    $st->close();
    if ($found) {
        if ((int) $age > 60) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
            $u = $db->prepare('UPDATE web_sessions SET last_seen_at = CURRENT_TIMESTAMP, ip = ?, user_agent = ? WHERE session_id = ?');
            if ($u) {
                $u->bind_param('sss', $ip, $ua, $id);
                $u->execute();
                $u->close();
            }
        }
        return true;
    }
    if (empty($_SESSION['ws_row'])) {
        // Signed in before rows existed: register instead of signing out.
        beta_ws_register();
        return true;
    }
    // Removed from Active Sessions, Log out everywhere, home logout or idle purge.
    $_SESSION = [];
    if (!headers_sent()) {
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    }
    session_destroy();
    return false;
}

function beta_json(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit;
}

// Beta dashboard version (the live dashboard keeps its own in config/main.php).
const BETA_DASHBOARD_VERSION = '6.0.0';
