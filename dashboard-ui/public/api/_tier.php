<?php
// Sets $_SESSION['tier'] for live includes/storage_used.php on beta. That
// include asks https://<host>/api/check_subscription.php, which does not exist
// on the beta host, so without this every beta user gets the free quota.
// Same Helix check as premium/status.php, cached in the session for 10 minutes.
// Call while the beta session is open.
function beta_ensure_tier(mysqli $conn): void {
    $userTwitchId = (string) ($_SESSION['twitchUserId'] ?? '');
    if ($userTwitchId === '') return;
    if (!empty($_SESSION['beta_tier_checked']) && time() - (int) $_SESSION['beta_tier_checked'] < 600 && isset($_SESSION['tier'])) return;
    require_once '/var/www/config/twitch.php';
    global $clientID;
    $broadcasterId = '140296994';
    $token = null;
    $stmt = $conn->prepare('SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = ?');
    if ($stmt) {
        $stmt->bind_param('s', $broadcasterId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $token = $row['twitch_access_token'] ?? null;
        $stmt->close();
    }
    if (!$token) return;
    $ch = curl_init('https://api.twitch.tv/helix/subscriptions?broadcaster_id=' . urlencode($broadcasterId) . '&user_id=' . urlencode($userTwitchId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Client-ID: ' . $clientID],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $httpCode !== 200) return;
    $data = json_decode((string) $response, true);
    $sub = $data['data'][0] ?? null;
    $_SESSION['tier'] = $sub ? (string) ($sub['tier'] ?? 'None') : 'None';
    $_SESSION['beta_tier_checked'] = time();
}
