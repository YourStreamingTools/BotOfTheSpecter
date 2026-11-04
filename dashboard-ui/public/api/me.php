<?php
require __DIR__ . '/_session.php';
beta_session_start();
if (empty($_SESSION['access_token']) || empty($_SESSION['twitchUserId'])) {
    beta_json(401, ['ok' => false, 'logged_in' => false]);
}
// Same admin check as the old menu: users.is_admin == 1 (dashboard/includes/userdata.php).
// Checked for the signed-in person, so the Admin Panel link stays while acting as someone.
$isAdmin = false;
// users.is_demo for the channel being viewed (the key sign-in's demo account,
// or a demo account an admin is acting as): no operational control.
$isDemo = false;
require_once '/var/www/config/db_connect.php';
if (isset($conn) && $conn instanceof mysqli) {
    $stmt = mysqli_prepare($conn, 'SELECT is_admin FROM users WHERE twitch_user_id = ? LIMIT 1');
    if ($stmt) {
        $tid = (string)beta_actor('twitchUserId');
        mysqli_stmt_bind_param($stmt, 's', $tid);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        $isAdmin = $row && (int)$row['is_admin'] === 1;
        mysqli_stmt_close($stmt);
    }
    // Before the migration adds users.is_demo this fails and stays false.
    try {
        $stmt = mysqli_prepare($conn, 'SELECT is_demo FROM users WHERE id = ? LIMIT 1');
        if ($stmt) {
            $uid = (int) ($_SESSION['user_id'] ?? 0);
            mysqli_stmt_bind_param($stmt, 'i', $uid);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            $isDemo = $row && (int) $row['is_demo'] === 1;
            mysqli_stmt_close($stmt);
        }
    } catch (Throwable $e) {
        $isDemo = false;
    }
}
beta_json(200, [
    'ok' => true,
    'logged_in' => true,
    'user_id' => $_SESSION['user_id'] ?? null,
    'username' => $_SESSION['username'] ?? null,
    'display_name' => $_SESSION['display_name'] ?? null,
    'profile_image' => $_SESSION['profile_image'] ?? null,
    'twitch_user_id' => $_SESSION['twitchUserId'],
    'is_admin' => $isAdmin,
    // Signed in with /login/key as an API key's demo account.
    'is_demo' => $isDemo,
    'dashboard_version' => BETA_DASHBOARD_VERSION,
    // Admin Act As (started from beta.admin Users): who is being viewed.
    'acting_as' => !empty($_SESSION['admin_act_as_active']) ? [
        'user_id' => (int) ($_SESSION['admin_act_as_target_user_id'] ?? 0),
        'username' => (string) ($_SESSION['admin_act_as_target_username'] ?? ''),
        'display_name' => (string) ($_SESSION['admin_act_as_target_display_name'] ?? ''),
    ] : null,
]);
