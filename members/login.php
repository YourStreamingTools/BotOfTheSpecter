<?php
// Set your Twitch application credentials
require_once "/var/www/config/twitch.php";
$redirectURI = 'https://members.botofthespecter.com/login.php';
$IDScope = 'openid user:read:email';

// Check if user just logged out
if (isset($_GET['logout']) && $_GET['logout'] === 'success') {
    $info = "You have been successfully logged out.";
} else {
    $info = "Please wait while we redirect you to Twitch for authorization.";
}

require_once '/var/www/lib/session_bootstrap.php';

// If the user is already logged in, redirect them to the original page or dashboard
if (isset($_SESSION['access_token'])) {
    if (isset($_SESSION['redirect_url'])) {
        $redirectUrl = filter_var($_SESSION['redirect_url'], FILTER_SANITIZE_URL);
        unset($_SESSION['redirect_url']);
        header("Location: $redirectUrl");
    } else {
        header('Location: ../');
    }
    exit;
}

// ----------------------------------------------------------------
// PATH A — Handoff token from home/sso.php (target='members')
// Members has its own session-key convention (snake_case + user_email)
// so we translate the handoff_tokens columns into members' shape and
// look up email from website.users (the handoff_tokens row doesn't
// carry it).
// ----------------------------------------------------------------
if (!empty($_GET['handoff'])) {
    $handoffToken = preg_replace('/[^a-f0-9]/i', '', (string)$_GET['handoff']);
    if (strlen($handoffToken) === 64) {
        require_once '/var/www/config/database.php';
        $wdb = new mysqli($db_servername, $db_username, $db_password, 'website');
        if (!$wdb->connect_error) {
            $stmt = $wdb->prepare(
                "SELECT twitch_user_id, username, display_name, access_token, refresh_token,
                        profile_image
                 FROM handoff_tokens
                 WHERE token = ? AND used = 0 AND expires_at > NOW()
                   AND (target IS NULL OR target = 'members')
                 LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('s', $handoffToken);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows === 1) {
                    $stmt->bind_result($twid, $uname, $dname, $at, $rt, $pimg);
                    $stmt->fetch();
                    $stmt->close();
                    // Mark token used so it can't be replayed
                    $u = $wdb->prepare("UPDATE handoff_tokens SET used = 1 WHERE token = ?");
                    if ($u) { $u->bind_param('s', $handoffToken); $u->execute(); $u->close(); }
                    // Look up email from website.users (handoff_tokens doesn't carry email)
                    $userEmail = '';
                    $eStmt = $wdb->prepare("SELECT email FROM users WHERE twitch_user_id = ? LIMIT 1");
                    if ($eStmt) {
                        $eStmt->bind_param('s', $twid);
                        $eStmt->execute();
                        $eStmt->bind_result($emailFromDb);
                        if ($eStmt->fetch()) $userEmail = (string)($emailFromDb ?? '');
                        $eStmt->close();
                    }
                    $wdb->close();
                    // Set members session — members reads snake_case keys
                    $_SESSION['access_token']      = $at;
                    $_SESSION['refresh_token']     = $rt;
                    $_SESSION['user_email']        = $userEmail;
                    $_SESSION['twitch_username']   = $uname;
                    $_SESSION['twitch_user_id']    = $twid;
                    $_SESSION['profile_image_url'] = $pimg;
                    $_SESSION['display_name']      = $dname;
                    if (isset($_SESSION['redirect_url'])) {
                        $redirectUrl = filter_var($_SESSION['redirect_url'], FILTER_SANITIZE_URL);
                        unset($_SESSION['redirect_url']);
                        header("Location: $redirectUrl");
                    } else {
                        header('Location: ../');
                    }
                    exit;
                }
                $stmt->close();
            }
            $wdb->close();
        }
        // Token invalid / expired — fall through to login page
        $info = 'Your session link has expired. Please sign in again.';
    }
}

// If the user is not logged in and we don't have any auth payload yet, render
// the login page with both SSO and Twitch buttons (instead of auto-redirecting).
if (!isset($_SESSION['access_token'])
    && !isset($_GET['code'])
    && !isset($_GET['logout'])
    && !isset($_GET['auth_data'])
    && !isset($_GET['auth_data_sig'])
    && !isset($_GET['server_token'])
    && !isset($_GET['handoff'])
) {
    $info = 'Sign in to access the members area.';
}

// Handle StreamersConnect auth_data response
if (isset($_GET['auth_data']) || isset($_GET['auth_data_sig']) || isset($_GET['server_token'])) {
    $decoded = null;
    $cfg = require_once "/var/www/config/main.php";
    $apiKey = isset($cfg['streamersconnect_api_key']) ? $cfg['streamersconnect_api_key'] : '';
    if (isset($_GET['auth_data_sig']) && $apiKey) {
        $sig = $_GET['auth_data_sig'];
        $ch = curl_init('https://streamersconnect.com/verify_auth_sig.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['auth_data_sig' => $sig]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $apiKey]);
        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response && $http === 200) {
            $res = json_decode($response, true);
            if (!empty($res['success']) && !empty($res['payload'])) $decoded = $res['payload'];
        }
    }
    if (!$decoded && isset($_GET['server_token']) && $apiKey) {
        $token = $_GET['server_token'];
        $ch = curl_init('https://streamersconnect.com/token_exchange.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['server_token' => $token]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $apiKey]);
        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response && $http === 200) {
            $res = json_decode($response, true);
            if (!empty($res['success']) && !empty($res['payload'])) $decoded = $res['payload'];
        }
    }
    if (!$decoded && isset($_GET['auth_data'])) {
        $decoded = json_decode(base64_decode($_GET['auth_data']), true);
    }

    if (!is_array($decoded) || empty($decoded['success'])) {
        $info = "Authentication failed or was cancelled.";
    } elseif (isset($decoded['service']) && $decoded['service'] === 'twitch') {
        $accessToken = $decoded['access_token'] ?? null;
        $refreshToken = $decoded['refresh_token'] ?? null;
        $user = $decoded['user'] ?? [];
        $twitchDisplayName = $user['display_name'] ?? null;
        $userEmail = $user['email'] ?? '';
        $twitchUsername = $user['login'] ?? $user['username'] ?? null;
        $profileImageUrl = $user['profile_image_url'] ?? null;
        $twitchUserId = $user['id'] ?? null;
        if ($accessToken && $twitchUserId) {
            // Store the tokens and basic user info in the session
            $_SESSION['access_token'] = $accessToken;
            $_SESSION['refresh_token'] = $refreshToken;
            $_SESSION['user_email'] = $userEmail;
            $_SESSION['twitch_username'] = $twitchUsername;
            $_SESSION['twitch_user_id'] = $twitchUserId;
            $_SESSION['profile_image_url'] = $profileImageUrl;
            $_SESSION['display_name'] = $twitchDisplayName;

            // Redirect to the original page or the dashboard
            if (isset($_SESSION['redirect_url'])) {
                $redirectUrl = filter_var($_SESSION['redirect_url'], FILTER_SANITIZE_URL);
                unset($_SESSION['redirect_url']);
                header("Location: $redirectUrl");
            } else {
                header('Location: ../');
            }
            exit;
        } else {
            $info = "Failed to parse authentication data from StreamersConnect.";
        }
    } else {
        $info = "Unexpected response service from StreamersConnect.";
    }
}

// If an authorization code is present, exchange it for an access token and refresh token
if (isset($_GET['code'])) {
    $code = $_GET['code'];

    // Exchange the authorization code for an access token and refresh token
    $tokenURL = 'https://id.twitch.tv/oauth2/token';
    $postData = array(
        'client_id' => $clientID,
        'client_secret' => $clientSecret,
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => $redirectURI
    );

    $curl = curl_init($tokenURL);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($curl);

    if ($response === false) {
        // Handle cURL error
        echo 'cURL error: ' . curl_error($curl);
        exit;
    }

    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        if ($httpCode === 403) {
            // Handle HTTP 403 Forbidden error
            echo 'HTTP error: 403 - Forbidden. Please check your client ID and secret.';
        } else {
            // Handle other non-successful HTTP responses
            echo 'HTTP error: ' . $httpCode;
        }
        exit;
    }

    curl_close($curl);

    // Extract the access token and refresh token from the response
    $responseData = json_decode($response, true);
    $accessToken = $responseData['access_token'];

    // Store the access token and refresh token in the session
    $_SESSION['access_token'] = $accessToken;

    // Fetch the user's Twitch username, profile image URL, and email address
    $userInfoURL = 'https://api.twitch.tv/helix/users';
    $curl = curl_init($userInfoURL);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $_SESSION['access_token'],
        'Client-ID: ' . $clientID
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $userInfoResponse = curl_exec($curl);

    if ($userInfoResponse === false) {
        // Handle cURL error
        echo 'cURL error: ' . curl_error($curl);
        exit;
    }

    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        if ($httpCode === 403) {
            // Handle HTTP 403 Forbidden error
            echo 'HTTP error: 403 - Forbidden. Please check your access token.';
        } else {
            // Handle other non-successful HTTP responses
            echo 'HTTP error: ' . $httpCode;
        }
        exit;
    }
    curl_close($curl);
    $userInfo = json_decode($userInfoResponse, true);
    if (isset($userInfo['data']) && count($userInfo['data']) > 0) {
        $twitchDisplayName = $userInfo['data'][0]['display_name'];
        $userEmail = $userInfo['data'][0]['email'];
        $twitchUsername = $userInfo['data'][0]['login'];
        $profileImageUrl = $userInfo['data'][0]['profile_image_url'];
        $twitchUserId = $userInfo['data'][0]['id'];

        // Store the user information in the session
        $_SESSION['user_email'] = $userEmail;
        $_SESSION['twitch_username'] = $twitchUsername;
        $_SESSION['twitch_user_id'] = $twitchUserId;
        $_SESSION['profile_image_url'] = $profileImageUrl;
        $_SESSION['display_name'] = $twitchDisplayName;

        // Redirect to the original page or the dashboard
        if (isset($_SESSION['redirect_url'])) {
            $redirectUrl = filter_var($_SESSION['redirect_url'], FILTER_SANITIZE_URL);
            unset($_SESSION['redirect_url']);
            header("Location: $redirectUrl");
        } else {
            header('Location: ../');
        }
        exit;
    }
}

// Build SSO and Twitch URLs for the login buttons rendered below.
$scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'members.botofthespecter.com';
$selfUrl   = $scheme . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/login.php');
$ssoUrl    = 'https://botofthespecter.com/sso.php?target=members&return=' . urlencode($selfUrl);
$twitchUrl = 'https://streamersconnect.com/?' . http_build_query([
    'service'    => 'twitch',
    'login'      => $host,
    'scopes'     => $IDScope,
    'return_url' => $selfUrl,
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter &mdash; Twitch Login</title>
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <link rel="stylesheet" href="/style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
</head>
<body class="sp-hero-page">
    <div class="sp-hero">
        <div class="sp-login-card">
            <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter Logo" style="width:80px;height:80px;object-fit:contain;border-radius:50%;margin-bottom:1rem;">
            <h1>BotOfTheSpecter</h1>
            <p class="sp-login-sub">Members Portal</p>
            <?php if (isset($_GET['logout']) && $_GET['logout'] === 'success'): ?>
                <div class="sp-alert sp-alert-info" style="margin:1rem 0;">
                    <?php echo htmlspecialchars($info, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php else: ?>
                <p style="color:var(--text-muted);font-size:0.9rem;margin:1rem 0;">
                    <?php echo htmlspecialchars($info, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            <?php endif; ?>
            <a href="<?php echo htmlspecialchars($ssoUrl); ?>" class="sp-btn sp-btn-primary" style="width:100%;justify-content:center;margin-top:0.5rem;">
                <img src="https://cdn.botofthespecter.com/logo.png" alt="" style="width:18px;height:18px;margin-right:8px;vertical-align:middle;">
                Sign in with BotOfTheSpecter
            </a>
            <a href="<?php echo htmlspecialchars($twitchUrl); ?>" class="sp-btn sp-btn-ghost" style="width:100%;justify-content:center;margin-top:0.5rem;">
                <i class="fa-brands fa-twitch"></i> Sign in with Twitch
            </a>
        </div>
    </div>
</body>
</html>