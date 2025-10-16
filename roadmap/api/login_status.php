<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$response = [
    'logged_in' => isset($_SESSION['username']),
    'username' => $_SESSION['username'] ?? null,
    'display_name' => $_SESSION['display_name'] ?? null,
    'admin' => isset($_SESSION['admin']) && $_SESSION['admin'],
    'twitch_id' => $_SESSION['twitch_id'] ?? null
];

echo json_encode($response);
?>