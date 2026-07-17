<?php
// roadmap/login.php
// ----------------------------------------------------------------
// Auth paths (same model as support/members):
//   A) Handoff token from home/sso.php (no re-auth when already signed in)
//   B) StreamersConnect → Twitch OAuth
// ----------------------------------------------------------------

require_once __DIR__ . '/includes/session.php';
roadmap_session_start();

if (roadmap_is_logged_in()) {
    $redirect = roadmap_safe_redirect($_SESSION['redirect_url'] ?? '/index.php');
    unset($_SESSION['redirect_url']);
    header('Location: ' . $redirect);
    exit;
}

$info     = 'Sign in to view and interact with the roadmap.';
$hasError = false;

// ----------------------------------------------------------------
// PATH A - Handoff token from home/sso.php
// ----------------------------------------------------------------
if (!empty($_GET['handoff'])) {
    $token = preg_replace('/[^a-f0-9]/i', '', (string)$_GET['handoff']);
    if (strlen($token) === 64) {
        $wdb  = website_db();
        $stmt = $wdb->prepare(
            "SELECT twitch_user_id, username, display_name, access_token, refresh_token,
                    profile_image, is_admin
             FROM handoff_tokens
             WHERE token = ? AND used = 0 AND expires_at > NOW()
               AND (target IS NULL OR target = 'roadmap')
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
                $u = $wdb->prepare("UPDATE handoff_tokens SET used = 1 WHERE token = ?");
                if ($u) { $u->bind_param('s', $token); $u->execute(); $u->close(); }
                $wdb->close();
                $_SESSION['access_token']   = $at;
                $_SESSION['refresh_token']  = $rt;
                $_SESSION['twitch_user_id'] = $twid;
                $_SESSION['twitchUserId']   = $twid;
                $_SESSION['username']       = $uname;
                $_SESSION['display_name']   = $dname;
                $_SESSION['profile_image']  = $pimg;
                $_SESSION['is_admin']       = (int)$iadmin;
                $_SESSION['last_validated_at'] = time();
                $_SESSION['twitch_expires_at'] = time() + 14400;
                roadmap_sync_auth();
                roadmap_init_admin_db();
                $redirect = roadmap_safe_redirect($_GET['return'] ?? ($_SESSION['redirect_url'] ?? '/index.php'));
                unset($_SESSION['redirect_url']);
                header('Location: ' . $redirect);
                exit;
            }
            $stmt->close();
        }
        $wdb->close();
        $info = 'Your session link has expired. Please log in again.';
        $hasError = true;
    }
}

// ----------------------------------------------------------------
// PATH B - StreamersConnect OAuth response
// ----------------------------------------------------------------
$cfg    = include '/var/www/config/main.php';
$apiKey = $cfg['streamersconnect_api_key'] ?? '';

if (isset($_GET['auth_data']) || isset($_GET['auth_data_sig']) || isset($_GET['server_token'])) {
    $decoded = null;
    if (!empty($_GET['auth_data_sig']) && $apiKey) {
        $sig = $_GET['auth_data_sig'];
        $ch  = curl_init('https://streamersconnect.com/verify_auth_sig.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['auth_data_sig' => $sig]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp && $http === 200) {
            $res = json_decode($resp, true);
            if (!empty($res['success']) && !empty($res['payload'])) $decoded = $res['payload'];
        }
    }
    if (!$decoded && !empty($_GET['server_token']) && $apiKey) {
        $tok = $_GET['server_token'];
        $ch  = curl_init('https://streamersconnect.com/token_exchange.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['server_token' => $tok]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp && $http === 200) {
            $res = json_decode($resp, true);
            if (!empty($res['success']) && !empty($res['payload'])) $decoded = $res['payload'];
        }
    }
    if (!$decoded && !empty($_GET['auth_data'])) {
        $decoded = json_decode(base64_decode($_GET['auth_data']), true);
    }
    if (!is_array($decoded) || empty($decoded['success'])) {
        $info     = 'Authentication failed or was cancelled. Please try again.';
        $hasError = true;
    } elseif (($decoded['service'] ?? '') !== 'twitch') {
        $info     = 'Unexpected authentication service. Please try again.';
        $hasError = true;
    } else {
        $user         = $decoded['user'] ?? [];
        $accessToken  = $decoded['access_token'] ?? null;
        $refreshToken = $decoded['refresh_token'] ?? null;
        $twitchUserId = $user['id'] ?? null;
        $uname        = $user['login'] ?? ($user['username'] ?? null);
        $dname        = $user['display_name'] ?? ($user['global_name'] ?? $uname);
        $pimg         = $user['profile_image_url'] ?? null;
        if ($accessToken && $twitchUserId) {
            $isAdmin = 0;
            $wdb     = website_db();
            $stmt    = $wdb->prepare("SELECT is_admin FROM users WHERE twitch_user_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $twitchUserId);
                $stmt->execute();
                $stmt->bind_result($isAdmin);
                if (!$stmt->fetch()) $isAdmin = 0;
                $stmt->close();
            }
            $wdb->close();
            $_SESSION['access_token']      = $accessToken;
            $_SESSION['refresh_token']     = $refreshToken;
            $_SESSION['twitch_user_id']    = $twitchUserId;
            $_SESSION['twitchUserId']      = $twitchUserId;
            $_SESSION['username']          = $uname;
            $_SESSION['display_name']      = $dname;
            $_SESSION['profile_image']     = $pimg;
            $_SESSION['is_admin']          = (int)$isAdmin;
            $expiresIn = isset($decoded['expires_in']) ? (int)$decoded['expires_in'] : 14400;
            $_SESSION['twitch_expires_at'] = time() + $expiresIn;
            $_SESSION['last_validated_at'] = time();
            roadmap_sync_auth();
            roadmap_init_admin_db();
            $redirect = roadmap_safe_redirect($_SESSION['redirect_url'] ?? '/index.php');
            unset($_SESSION['redirect_url']);
            header('Location: ' . $redirect);
            exit;
        }
        $info     = 'Failed to read authentication data. Please try again.';
        $hasError = true;
    }
}

$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'roadmap.botofthespecter.com';
$selfUrl = $scheme . '://' . $host . '/index.php';
$ssoUrl  = 'https://botofthespecter.com/sso.php?target=roadmap&return=' . urlencode($selfUrl);
$BOTS_SSO_SCOPES = 'openid channel:bot moderator:manage:chat_messages user:read:moderated_channels moderator:read:blocked_terms moderator:read:chat_settings moderator:read:vips moderator:read:moderators moderator:read:unban_requests moderator:read:banned_users moderator:read:chat_messages moderator:read:warnings user:bot channel:read:goals channel:moderate channel:manage:moderators user:edit:broadcast channel:manage:redemptions channel:manage:polls channel:manage:predictions moderator:manage:automod moderator:read:suspicious_users channel:read:hype_train channel:manage:broadcast channel:manage:raids channel:read:charity user:read:email user:read:chat user:write:chat user:read:follows chat:read chat:edit moderation:read moderator:read:followers channel:read:redemptions channel:read:vips channel:manage:vips user:read:subscriptions channel:read:subscriptions moderator:read:chatters bits:read channel:manage:ads channel:read:ads channel:manage:schedule channel:manage:clips editor:manage:clips clips:edit moderator:manage:announcements moderator:manage:banned_users moderator:manage:chat_messages moderator:read:shoutouts moderator:manage:shoutouts user:read:blocked_users user:manage:blocked_users';
$twitchUrl = 'https://streamersconnect.com/?' . http_build_query([
    'service'    => 'twitch',
    'login'      => $host,
    'scopes'     => $BOTS_SSO_SCOPES,
    'return_url' => $scheme . '://' . $host . '/login.php',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - BotOfTheSpecter Roadmap</title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <div class="rm-login-wrap">
        <div class="rm-login-card">
            <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter" class="rm-login-logo">
            <h1 class="rm-login-title">BotOfTheSpecter Roadmap</h1>
            <p class="rm-login-sub"><?php echo htmlspecialchars($info); ?></p>

            <?php if ($hasError): ?>
                <div class="sp-alert sp-alert-danger" style="width:100%;margin-bottom:1rem;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span><?php echo htmlspecialchars($info); ?></span>
                </div>
            <?php endif; ?>

            <a href="<?php echo htmlspecialchars($ssoUrl); ?>" class="sp-btn sp-btn-primary" style="width:100%;justify-content:center;font-size:1rem;padding:0.75rem 1.5rem;margin-bottom:0.75rem;">
                <img src="https://cdn.botofthespecter.com/logo.png" alt="" style="width:18px;height:18px;margin-right:8px;vertical-align:middle;">
                Sign in with BotOfTheSpecter
            </a>
            <a href="<?php echo htmlspecialchars($twitchUrl); ?>" class="sp-btn sp-btn-secondary" style="width:100%;justify-content:center;font-size:1rem;padding:0.75rem 1.5rem;">
                <i class="fa-brands fa-twitch"></i> Sign in with Twitch
            </a>
            <?php if ($hasError): ?>
                <a href="/index.php" class="sp-btn sp-btn-secondary" style="width:100%;justify-content:center;margin-top:0.75rem;">
                    <i class="fa-solid fa-arrow-left"></i> Back to Roadmap
                </a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>