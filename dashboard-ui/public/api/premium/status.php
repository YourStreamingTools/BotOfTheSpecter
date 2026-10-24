<?php
// Premium status for the beta dashboard. Same logic as the live
// dashboard/api/check_subscription.php plus premium.php's beta_access read:
// the broadcaster's token from twitch_bot_access asks Helix whether this
// user subscribes to the broadcaster channel.
require __DIR__ . '/../_session.php';
beta_session_start();
if (empty($_SESSION['access_token']) || empty($_SESSION['twitchUserId'])) {
    beta_json(401, ['success' => false, 'session_expired' => true]);
}
$userTwitchId = (string) $_SESSION['twitchUserId'];
session_write_close();

require_once '/var/www/config/db_connect.php';
require_once '/var/www/config/twitch.php';
$broadcasterId = '140296994';

$betaAccess = false;
$stmt = $conn->prepare('SELECT beta_access FROM users WHERE twitch_user_id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('s', $userTwitchId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $betaAccess = $row && (int) $row['beta_access'] === 1;
    $stmt->close();
}

$out = ['success' => true, 'beta_access' => $betaAccess, 'checked' => false, 'subscribed' => false, 'tier' => null, 'is_gift' => false, 'error' => null];

$token = null;
$stmt = $conn->prepare('SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = ?');
if ($stmt) {
    $stmt->bind_param('s', $broadcasterId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $token = $row['twitch_access_token'] ?? null;
    $stmt->close();
}
if (!$token) {
    $out['error'] = 'Broadcaster token not found.';
    beta_json(200, $out);
}

$ch = curl_init('https://api.twitch.tv/helix/subscriptions?broadcaster_id=' . urlencode($broadcasterId) . '&user_id=' . urlencode($userTwitchId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Client-ID: ' . $clientID],
]);
$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);
if ($response === false || $httpCode !== 200) {
    error_log("beta-dashboard premium: Twitch subscriptions HTTP $httpCode");
    $out['error'] = $response === false ? $curlError : "Twitch API returned HTTP $httpCode.";
    beta_json(200, $out);
}
$data = json_decode((string) $response, true);
$sub = $data['data'][0] ?? null;
$out['checked'] = true;
if ($sub) {
    $out['subscribed'] = true;
    $out['tier'] = (string) ($sub['tier'] ?? '');
    $out['is_gift'] = (bool) ($sub['is_gift'] ?? false);
}
beta_json(200, $out);
