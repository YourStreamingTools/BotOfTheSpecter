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
    beta_act_as_apply();
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

// Act As (admin from beta.admin Users, or a moderator from Mod Channels).
// While it is active the session's identity keys hold the viewed channel, the
// same keys dashboard/includes/userdata.php sets on the live dashboard, so every
// endpoint loads that channel's data. The signed-in person is kept in
// admin_act_as_original, their own Twitch token stays in access_token, and this
// session's web_sessions row stays theirs.
function beta_act_as_snapshot(): array
{
    return [
        'user_id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'display_name' => $_SESSION['display_name'] ?? null,
        'profile_image' => $_SESSION['profile_image'] ?? null,
        'twitchUserId' => $_SESSION['twitchUserId'] ?? null,
        'access_token' => $_SESSION['access_token'] ?? null,
        'refresh_token' => $_SESSION['refresh_token'] ?? null,
        'api_key' => $_SESSION['api_key'] ?? null,
        'is_admin' => $_SESSION['is_admin'] ?? false,
        'beta_access' => $_SESSION['beta_access'] ?? false,
        'beta_programs' => $_SESSION['beta_programs'] ?? [],
        'use_custom' => $_SESSION['use_custom'] ?? 0,
        'use_self' => $_SESSION['use_self'] ?? 0,
        'use_custom_module' => $_SESSION['use_custom_module'] ?? 0,
        'user_data' => $_SESSION['user_data'] ?? null,
    ];
}

// The signed-in person's own value for a session key, also while acting as someone.
function beta_actor(string $key): mixed
{
    $o = $_SESSION['admin_act_as_original'] ?? null;
    return is_array($o) ? ($o[$key] ?? null) : ($_SESSION[$key] ?? null);
}

// Puts the viewed channel's users row into the session once per target.
function beta_act_as_apply(): void
{
    if (empty($_SESSION['admin_act_as_active'])) return;
    $target = (int) ($_SESSION['admin_act_as_target_user_id'] ?? 0);
    if ($target <= 0 || (int) ($_SESSION['admin_act_as_applied'] ?? 0) === $target) return;
    $db = beta_ws_db();
    if (!$db) return;
    $st = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    if (!$st) return;
    $st->bind_param('i', $target);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$u) return;
    // Act As started before display_name/profile_image were saved: keep them
    // now, while the session still holds the signed-in person's.
    if (isset($_SESSION['admin_act_as_original']) && is_array($_SESSION['admin_act_as_original'])) {
        foreach (['display_name', 'profile_image'] as $k) {
            if (!array_key_exists($k, $_SESSION['admin_act_as_original'])) $_SESSION['admin_act_as_original'][$k] = $_SESSION[$k] ?? null;
        }
    }
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['username'] = $u['username'];
    $_SESSION['display_name'] = $u['twitch_display_name'] ?: $u['username'];
    $_SESSION['profile_image'] = $u['profile_image'];
    $_SESSION['twitchUserId'] = $u['twitch_user_id'];
    $_SESSION['api_key'] = $u['api_key'];
    $_SESSION['is_admin'] = ((int) $u['is_admin'] === 1);
    $_SESSION['beta_access'] = ((int) $u['beta_access'] === 1);
    $_SESSION['beta_programs'] = json_decode($u['beta_programs'] ?? '[]', true) ?? [];
    $_SESSION['use_custom'] = $u['use_custom'];
    $_SESSION['use_self'] = $u['use_self'];
    $_SESSION['use_custom_module'] = (int) ($u['use_custom_module'] ?? 0);
    $_SESSION['user_data'] = $u;
    $_SESSION['admin_act_as_applied'] = $target;
}

// Drops every Act As key (the identity keys are left as they are).
function beta_act_as_clear(): void
{
    unset(
        $_SESSION['admin_act_as_active'], $_SESSION['admin_act_as_started_at'], $_SESSION['admin_act_as_actor_user_id'],
        $_SESSION['admin_act_as_actor_username'], $_SESSION['admin_act_as_actor_role'], $_SESSION['admin_act_as_target_user_id'],
        $_SESSION['admin_act_as_target_username'], $_SESSION['admin_act_as_target_display_name'], $_SESSION['admin_act_as_original'],
        $_SESSION['admin_act_as_applied'],
        $_SESSION['editing_user'], $_SESSION['editing_username'], $_SESSION['editing_display_name'], $_SESSION['editing_profile_image'],
        $_SESSION['editing_access_token'], $_SESSION['editing_refresh_token'], $_SESSION['editing_api_key'],
        $_SESSION['mod_act_as_active'], $_SESSION['mod_act_as_started_at'], $_SESSION['mod_act_as_actor_username'],
        $_SESSION['mod_act_as_target_user_id'], $_SESSION['mod_act_as_target_username'], $_SESSION['mod_act_as_target_display_name']
    );
}

function beta_ws_id(?string $sid = null): string
{
    return 'beta:' . ($sid ?? session_id());
}

// Insert or refresh this session's row (login and legacy sessions).
function beta_ws_register(): bool
{
    // Always the signed-in person's row, also while acting as another channel.
    $db = beta_ws_db();
    if (!$db || empty(beta_actor('twitchUserId'))) return false;
    $id = beta_ws_id();
    $uid = (string) beta_actor('twitchUserId');
    $user = (string) (beta_actor('username') ?? '');
    $name = (string) (beta_actor('display_name') ?? '');
    $img = (string) (beta_actor('profile_image') ?? '');
    $admin = (int) (beta_actor('is_admin') ?? 0);
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
    $uid = (string) beta_actor('twitchUserId');
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
