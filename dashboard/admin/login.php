<?php
// admin.botofthespecter.com/login.php
// Operators only. No public Twitch button on this host.
// Path A: handoff from home/sso.php?target=admin
// Path B: already have bots_session and is_admin

require_once '/var/www/lib/session_bootstrap.php';
require_once '/var/www/config/database.php';

function admin_login_safe_return($raw): string
{
    $rt = (string) $raw;
    if ($rt !== '' && strncmp($rt, '/', 1) === 0 && strncmp($rt, '//', 2) !== 0) {
        return $rt;
    }
    return '/index.php';
}

if (!empty($_GET['return'])) {
    $_SESSION['redirect_url'] = admin_login_safe_return($_GET['return']);
} elseif (!empty($_GET['return_to'])) {
    $_SESSION['redirect_url'] = admin_login_safe_return($_GET['return_to']);
}

$goHome = static function (): void {
    $redirect = $_SESSION['redirect_url'] ?? $_SESSION['redirect_after_login'] ?? '/index.php';
    unset($_SESSION['redirect_url'], $_SESSION['redirect_after_login']);
    if (strncmp((string) $redirect, '/', 1) !== 0 || strncmp((string) $redirect, '//', 2) === 0) {
        $redirect = '/index.php';
    }
    header('Location: ' . $redirect);
    exit;
};

if (!empty($_SESSION['access_token']) && (int) ($_SESSION['is_admin'] ?? 0) === 1) {
    $goHome();
}

if (!empty($_SESSION['access_token']) && (int) ($_SESSION['is_admin'] ?? 0) !== 1 && empty($_GET['handoff'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Admin only.\n";
    exit;
}

if (!empty($_GET['handoff'])) {
    $token = preg_replace('/[^a-f0-9]/i', '', (string) $_GET['handoff']);
    if (strlen($token) === 64) {
        $wdb = new mysqli($db_servername, $db_username, $db_password, 'website');
        if (!$wdb->connect_error) {
            $wdb->set_charset('utf8mb4');
            $stmt = $wdb->prepare(
                "SELECT twitch_user_id, username, display_name, access_token, refresh_token,
                        profile_image, is_admin
                 FROM handoff_tokens
                 WHERE token = ? AND used = 0 AND expires_at > NOW()
                   AND (target IS NULL OR target = 'admin')
                 LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('s', $token);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows === 1) {
                    $stmt->bind_result($twid, $uname, $dname, $at, $rt, $pimg, $iadmin);
                    $stmt->fetch();
                    $stmt->close();
                    $dbAdmin = 0;
                    $uStmt = $wdb->prepare('SELECT is_admin FROM users WHERE twitch_user_id = ? LIMIT 1');
                    if ($uStmt) {
                        $uStmt->bind_param('s', $twid);
                        $uStmt->execute();
                        $uStmt->bind_result($fromUsers);
                        if ($uStmt->fetch()) {
                            $dbAdmin = (int) $fromUsers;
                        }
                        $uStmt->close();
                    }
                    if ((int) $iadmin !== 1 || $dbAdmin !== 1) {
                        $wdb->close();
                        http_response_code(403);
                        header('Content-Type: text/plain; charset=utf-8');
                        echo "Admin only.\n";
                        exit;
                    }
                    $mark = $wdb->prepare('UPDATE handoff_tokens SET used = 1 WHERE token = ?');
                    if ($mark) {
                        $mark->bind_param('s', $token);
                        $mark->execute();
                        $mark->close();
                    }
                    $wdb->close();
                    $_SESSION['access_token'] = $at;
                    $_SESSION['refresh_token'] = $rt;
                    $_SESSION['twitchUserId'] = $twid;
                    $_SESSION['twitch_user_id'] = $twid;
                    $_SESSION['username'] = $uname;
                    $_SESSION['display_name'] = $dname;
                    $_SESSION['profile_image'] = $pimg;
                    $_SESSION['is_admin'] = 1;
                    $_SESSION['last_validated_at'] = time();
                    $_SESSION['twitch_expires_at'] = time() + 14400;
                    $goHome();
                }
                $stmt->close();
            }
            $wdb->close();
        }
    }
}

$selfReturn = $_SESSION['redirect_url'] ?? '/index.php';
header('Location: https://botofthespecter.com/sso.php?target=admin&return=' . rawurlencode($selfReturn));
exit;
