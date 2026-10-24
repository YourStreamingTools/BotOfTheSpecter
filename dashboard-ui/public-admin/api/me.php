<?php
// Who is signed in. 401 when not signed in, 403 when not an admin (_admin.php).
require __DIR__ . '/_admin.php';
badm_json(200, [
    'ok' => true,
    'user_id' => $_SESSION['user_id'] ?? null,
    'username' => $_SESSION['username'] ?? null,
    'display_name' => $_SESSION['display_name'] ?? null,
    'profile_image' => $_SESSION['profile_image'] ?? null,
    'twitch_user_id' => $_SESSION['twitchUserId'],
    'is_admin' => true,
]);
