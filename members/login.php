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

// Set session timeout to 24 hours (86400 seconds)
session_set_cookie_params(86400, "/", "", true, true);
ini_set('session.gc_maxlifetime', 86400);
ini_set('session.cookie_lifetime', 86400);

// Start PHP session
session_start();

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

// If the user is not logged in and no authorization code or auth_data is present, redirect to StreamersConnect
// UNLESS they just logged out (in which case, show them the logout message)
if (!isset($_SESSION['access_token']) && !isset($_GET['code']) && !isset($_GET['logout']) && !isset($_GET['auth_data'])) {
    // Build StreamersConnect URL
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
    $originDomain = $_SERVER['HTTP_HOST'];
    $returnUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $streamersconnectBase = 'https://streamersconnect.com/';
    $scopes = $IDScope;
    $authUrl = $streamersconnectBase . '?' . http_build_query([
        'service' => 'twitch',
        'login' => $originDomain,
        'scopes' => $scopes,
        'return_url' => $returnUrl
    ]);
    header('Location: ' . $authUrl);
    exit;
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
?>
<!DOCTYPE html>
<html>

<head>
    <title>BotOfTheSpecter - Twitch Login</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="32x32" />
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png" sizes="192x192" />
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png" />
    <meta name="msapplication-TileImage" content="https://cdn.botofthespecter.com/logo.png" />
</head>

<body>
    <section class="hero is-fullheight is-dark">
        <div class="hero-body">
            <div class="container has-text-centered">
                <div class="box" style="max-width: 400px; margin: 0 auto;">
                    <figure class="image is-128x128 is-inline-block">
                        <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter Logo">
                    </figure>
                    <h1 class="title is-4 mt-3 has-text-black has-text-weight-bold">BotOfTheSpecter</h1>
                    <?php if (isset($_GET['logout']) && $_GET['logout'] === 'success'): ?>
                        <div class="notification is-success is-light">
                            <p><?php echo $info; ?></p>
                        </div>
                        <a href="login.php" class="button is-primary is-fullwidth mt-3">
                            <span class="icon">
                                <i class="fab fa-twitch"></i>
                            </span>
                            <span>Login with Twitch</span>
                        </a>
                    <?php else: ?>
                        <p>
                            <?php echo $info; ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</body>

</html>