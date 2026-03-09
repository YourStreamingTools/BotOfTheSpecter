<?php
// support/login.php
// ----------------------------------------------------------------
// Two auth paths:
//   A) Handoff token from dashboard (no re-auth required)
//   B) StreamersConnect → Twitch OAuth (identical to dashboard flow)
// ----------------------------------------------------------------

require_once __DIR__ . '/includes/session.php';
support_session_start();

// Already logged in → go home
if (!empty($_SESSION['access_token'])) {
    $redirect = $_SESSION['redirect_url'] ?? '/index.php';
    unset($_SESSION['redirect_url']);
    header('Location: ' . $redirect);
    exit;
}

$info     = 'Please wait while we connect you…';
$hasError = false;

// ----------------------------------------------------------------
// PATH A — Handoff token from dashboard
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
                // Mark token as used
                $u = $wdb->prepare("UPDATE handoff_tokens SET used = 1 WHERE token = ?");
                if ($u) { $u->bind_param('s', $token); $u->execute(); $u->close(); }
                $wdb->close();
                // Set session
                $_SESSION['access_token']  = $at;
                $_SESSION['refresh_token'] = $rt;
                $_SESSION['twitch_user_id'] = $twid;
                $_SESSION['username']      = $uname;
                $_SESSION['display_name']  = $dname;
                $_SESSION['profile_image'] = $pimg;
                $_SESSION['is_admin']      = (int)$iadmin;
                $redirect = $_SESSION['redirect_url'] ?? '/index.php';
                unset($_SESSION['redirect_url']);
                header('Location: ' . $redirect);
                exit;
            }
            $stmt->close();
        }
        $wdb->close();
        // Token invalid / expired — fall through to StreamersConnect
        $info = 'Your session link has expired. Please log in with Twitch.';
    }
}

// ----------------------------------------------------------------
// PATH B — StreamersConnect OAuth response
// ----------------------------------------------------------------
$cfg    = include '/var/www/config/main.php';
$apiKey = $cfg['streamersconnect_api_key'] ?? '';

if (isset($_GET['auth_data']) || isset($_GET['auth_data_sig']) || isset($_GET['server_token'])) {
    $decoded = null;
    // Prefer server-side verification via auth_data_sig
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
        curl_close($ch);
        if ($resp && $http === 200) {
            $res = json_decode($resp, true);
            if (!empty($res['success']) && !empty($res['payload'])) $decoded = $res['payload'];
        }
    }
    // Fall back to server_token exchange
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
        curl_close($ch);
        if ($resp && $http === 200) {
            $res = json_decode($resp, true);
            if (!empty($res['success']) && !empty($res['payload'])) $decoded = $res['payload'];
        }
    }
    // Legacy base64 fallback
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
            // Look up is_admin from website.users
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
            $_SESSION['access_token']   = $accessToken;
            $_SESSION['refresh_token']  = $refreshToken;
            $_SESSION['twitch_user_id'] = $twitchUserId;
            $_SESSION['username']       = $uname;
            $_SESSION['display_name']   = $dname;
            $_SESSION['profile_image']  = $pimg;
            $_SESSION['is_admin']       = (int)$isAdmin;
            $redirect = $_SESSION['redirect_url'] ?? '/index.php';
            unset($_SESSION['redirect_url']);
            header('Location: ' . $redirect);
            exit;
        }
        $info     = 'Failed to read authentication data. Please try again.';
        $hasError = true;
    }
}

// ----------------------------------------------------------------
// No auth data yet — redirect to StreamersConnect unless error
// ----------------------------------------------------------------
if (!$hasError && empty($_GET['auth_data']) && empty($_GET['auth_data_sig']) && empty($_GET['server_token']) && empty($_GET['handoff'])) {
    $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'] ?? 'support.botofthespecter.com';
    $returnUrl = $scheme . '://' . $host . '/login.php';
    $authUrl   = 'https://streamersconnect.com/?' . http_build_query([
        'service'    => 'twitch',
        'login'      => $host,
        'scopes'     => 'openid user:read:email',
        'return_url' => $returnUrl,
    ]);
    header('Location: ' . $authUrl);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In — BotOfTheSpecter Support</title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <link rel="stylesheet" href="/css/style.css?v=<?php echo bin2hex(random_bytes(4)); ?>">
</head>
<body>
<div class="sp-hero">
    <div class="sp-login-card">
        <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter" class="sp-login-logo">
        <h1>BotOfTheSpecter Support</h1>
        <p><?php echo htmlspecialchars($info); ?></p>
        <?php if ($hasError): ?>
            <a href="/login.php" class="sp-btn sp-btn-primary" style="width:100%;justify-content:center;">
                <i class="fa-brands fa-twitch"></i> Try Again with Twitch
            </a>
            <a href="/index.php" class="sp-btn sp-btn-ghost sp-mt-1" style="width:100%;justify-content:center;">
                <i class="fa-solid fa-arrow-left"></i> Back to Docs
            </a>
        <?php else: ?>
            <div class="sp-spinner"></div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>