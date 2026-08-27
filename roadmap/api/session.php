<?php
require_once dirname(__DIR__) . '/includes/session.php';
roadmap_session_start();

$loggedIn = roadmap_is_logged_in();
$username = $loggedIn ? (string) ($_SESSION['username'] ?? '') : '';
$photo = $loggedIn
    ? (string) ($_SESSION['profile_image'] ?? $_SESSION['profile_image_url'] ?? '')
    : '';
if ($loggedIn && $photo === '' && $username !== '') {
    $wdb = website_db();
    $stmt = $wdb->prepare('SELECT profile_image FROM users WHERE username = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->bind_result($dbPhoto);
        if ($stmt->fetch() && is_string($dbPhoto) && $dbPhoto !== '') {
            $photo = $dbPhoto;
            $_SESSION['profile_image'] = $dbPhoto;
        }
        $stmt->close();
    }
    $wdb->close();
}

json_out([
    'ok' => true,
    'logged_in' => $loggedIn,
    'is_admin' => $loggedIn && roadmap_is_admin(),
    'username' => $loggedIn ? ($username !== '' ? $username : null) : null,
    'display_name' => $loggedIn ? ($_SESSION['display_name'] ?? ($username !== '' ? $username : null)) : null,
    'profile_image' => $photo !== '' ? $photo : null,
    'csrf_token' => roadmap_csrf_token(),
]);
