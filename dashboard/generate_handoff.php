<?php
// dashboard/generate_handoff.php
// ----------------------------------------------------------------
// Generates a single-use handoff token and redirects the
// user to support.botofthespecter.com for seamless SSO.
//
// Called from the dashboard via a "Support" nav link.
// Requires an active dashboard session.
// ----------------------------------------------------------------

session_start();
session_write_close();

// Must be logged in to the dashboard
if (empty($_SESSION['access_token']) || empty($_SESSION['twitchUserId'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: /login.php');
    exit;
}

// Load database config (provides $db_servername, $db_username, $db_password)
require_once '/var/www/config/database.php';

// Connect directly to the website DB
$conn = new mysqli($db_servername, $db_username, $db_password, 'website');
if ($conn->connect_error) {
    error_log('[generate_handoff] DB connect failed: ' . $conn->connect_error);
    header('Location: https://botofthespecter.com');
    exit;
}

// Generate a secure 64-char hex token
$token = bin2hex(random_bytes(32));

// Pull session values (set during dashboard login)
$twitch_user_id  = $_SESSION['twitchUserId']    ?? '';
$username        = $_SESSION['username']         ?? '';
$display_name    = $_SESSION['display_name']     ?? $username;
$access_token    = $_SESSION['access_token']     ?? '';
$refresh_token   = $_SESSION['refresh_token']    ?? '';
$profile_image   = $_SESSION['profile_image']    ?? '';
$is_admin        = (int)($_SESSION['is_admin']   ?? 0);
$stmt = $conn->prepare(
    'INSERT INTO handoff_tokens
        (token, twitch_user_id, username, display_name, access_token, refresh_token, profile_image, is_admin, expires_at, used)
     VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0)'
);

if (!$stmt) {
    error_log('[generate_handoff] prepare failed: ' . $conn->error);
    $conn->close();
    header('Location: https://botofthespecter.com');
    exit;
}

$stmt->bind_param('sssssssi',
    $token,
    $twitch_user_id,
    $username,
    $display_name,
    $access_token,
    $refresh_token,
    $profile_image,
    $is_admin
);

if (!$stmt->execute()) {
    error_log('[generate_handoff] execute failed: ' . $stmt->error);
    $stmt->close();
    $conn->close();
    header('Location: https://support.botofthespecter.com/login.php');
    exit;
}

$stmt->close();
$conn->close();

// Redirect to support with the handoff token
header('Location: https://support.botofthespecter.com/login.php?handoff=' . urlencode($token));
exit;
?>
