<?php
require_once '/var/www/lib/session_bootstrap.php';
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';

function userCanModerateChannel($targetBroadcasterId, $userId, $authToken, $clientID) {
    if ($targetBroadcasterId === '' || $userId === '' || $authToken === '' || $clientID === '') {
        return false;
    }
    $cursor = null;
    do {
        $url = "https://api.twitch.tv/helix/moderation/channels?user_id=" . urlencode($userId) . "&first=100";
        if (!empty($cursor)) {
            $url .= "&after=" . urlencode($cursor);
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $authToken,
            'Client-ID: ' . $clientID,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $httpCode !== 200) {
            error_log("switch_channel.php moderated-channels check failed. HTTP: {$httpCode}, user: {$userId}, target: {$targetBroadcasterId}");
            return false;
        }
        $data = json_decode($response, true);
        if (!isset($data['data']) || !is_array($data['data'])) {
            return false;
        }
        foreach ($data['data'] as $channel) {
            if ((string)($channel['broadcaster_id'] ?? '') === (string)$targetBroadcasterId) {
                return true;
            }
        }
        $cursor = $data['pagination']['cursor'] ?? null;
    } while (!empty($cursor));
    return false;
}

if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

if (!isset($_GET['user_id'])) {
    header('Location: bot.php');
    exit();
}

$targetUserId = (string) $_GET['user_id'];

// Use original actor context when already acting as another user
$actorContext = (isset($_SESSION['admin_act_as_original']) && is_array($_SESSION['admin_act_as_original']))
    ? $_SESSION['admin_act_as_original']
    : [
        'user_id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? '',
        'twitchUserId' => $_SESSION['twitchUserId'] ?? '',
        'access_token' => $_SESSION['access_token'] ?? null,
        'refresh_token' => $_SESSION['refresh_token'] ?? null,
        'api_key' => $_SESSION['api_key'] ?? null,
        'is_admin' => $_SESSION['is_admin'] ?? false,
        'beta_access' => $_SESSION['beta_access'] ?? false,
        'use_custom' => $_SESSION['use_custom'] ?? 0,
        'use_self' => $_SESSION['use_self'] ?? 0,
        'user_data' => $_SESSION['user_data'] ?? null,
    ];

$actorUsername = strtolower(trim((string) ($actorContext['username'] ?? '')));
$actorTwitchUserId = trim((string) ($actorContext['twitchUserId'] ?? ''));
$actorAccessToken = trim((string) ($actorContext['access_token'] ?? ''));
$actorIsAdmin = !empty($actorContext['is_admin']);
$hasAccess = $actorIsAdmin || $actorUsername === 'botofthespecter';

if (!$hasAccess && $actorTwitchUserId !== '' && $targetUserId !== '') {
    // Release session lock before Twitch API call
    session_write_close();
    $hasAccess = userCanModerateChannel($targetUserId, $actorTwitchUserId, $actorAccessToken, (string)($clientID ?? ''));
    session_start(); // Reopen for session writes below
}

if (!$hasAccess) {
    header('Location: mod_channels.php?act_as=denied');
    exit();
}

// Fetch target user info for Act As
$stmt = $conn->prepare("SELECT id, username, twitch_display_name, profile_image, twitch_user_id, access_token, refresh_token, api_key, is_admin, beta_access, use_custom, use_self, email FROM users WHERE twitch_user_id = ? LIMIT 1");
$stmt->bind_param("s", $targetUserId);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    if (!isset($_SESSION['admin_act_as_original']) || !is_array($_SESSION['admin_act_as_original'])) {
        $_SESSION['admin_act_as_original'] = $actorContext;
    }
    unset(
        $_SESSION['editing_user'],
        $_SESSION['editing_username'],
        $_SESSION['editing_display_name'],
        $_SESSION['editing_profile_image'],
        $_SESSION['editing_access_token'],
        $_SESSION['editing_refresh_token'],
        $_SESSION['editing_api_key'],
        $_SESSION['mod_act_as_active'],
        $_SESSION['mod_act_as_started_at'],
        $_SESSION['mod_act_as_actor_username'],
        $_SESSION['mod_act_as_target_user_id'],
        $_SESSION['mod_act_as_target_username'],
        $_SESSION['mod_act_as_target_display_name']
    );
    $_SESSION['user_data'] = null;
    // Shared Act As context for banner/restore
    $_SESSION['admin_act_as_active'] = true;
    $_SESSION['admin_act_as_started_at'] = time();
    $_SESSION['admin_act_as_actor_user_id'] = $actorContext['user_id'] ?? null;
    $_SESSION['admin_act_as_actor_username'] = $actorContext['username'] ?? '';
    $_SESSION['admin_act_as_actor_role'] = $actorIsAdmin ? 'admin' : 'moderator';
    $_SESSION['admin_act_as_target_user_id'] = (int) ($row['id'] ?? 0);
    $_SESSION['admin_act_as_target_username'] = $row['username'] ?? '';
    $_SESSION['admin_act_as_target_display_name'] = $row['twitch_display_name'] ?? '';
    header('Location: dashboard.php');
    exit();
} else {
    // Invalid user/channel
    header('Location: mod_channels.php?act_as=not_found');
    exit();
}
?>