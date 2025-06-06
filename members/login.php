<?php
// Set your Twitch application credentials
require_once "/var/www/config/twitch.php";
$redirectURI = 'https://members.botofthespecter.com/login.php';
$IDScope = 'openid user:read:email';
$info = "Please wait while we redirect you to Twitch for authorization.";

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

// If the user is not logged in and no authorization code is present, redirect to Twitch authorization page
if (!isset($_SESSION['access_token']) && !isset($_GET['code'])) {
    header('Location: https://id.twitch.tv/oauth2/authorize' .
        '?client_id=' . $clientID .
        '&redirect_uri=' . urlencode($redirectURI) .
        '&response_type=code' .
        '&scope=' . urlencode($IDScope));
    exit;
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
                <h1 class="title is-4 mt-3">BotOfTheSpecter</h1>
                <p><?php echo $info; ?></p>
            </div>
        </div>
    </div>
</section>
</body>
</html>