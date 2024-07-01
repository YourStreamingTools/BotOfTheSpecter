<?php
// Set your Twitch application credentials
$clientID = 'YOUR_CLIENT_ID'; # Fill this out to make it work
$redirectURI = 'https://songlist.botofthespecter.com/login.php';
$clientSecret = 'YOUR_CLIENT_SECRET'; # Fill this out to make it work
$IDScope = 'openid user:read:email';

// Start PHP session
session_start();

// If the user is already logged in, redirect them to the index page
if (isset($_SESSION['access_token'])) {
    header('Location: index.php');
    exit;
}

// If the user is not logged in and no authorization code is present, redirect to Twitch authorization page
if (!isset($_SESSION['access_token']) && !isset($_GET['code'])) {
    header('Location: https://id.twitch.tv/oauth2/authorize' .
        '?client_id=' . $clientID .
        '&redirect_uri=' . $redirectURI .
        '&response_type=code' .
        '&scope=' . $IDScope);
    exit;
}

// If an authorization code is present, exchange it for an access token
if (isset($_GET['code'])) {
    $code = $_GET['code'];

    // Exchange the authorization code for an access token
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
        // Handle non-successful HTTP response
        echo 'HTTP error: ' . $httpCode;
        exit;
    }

    curl_close($curl);

    // Extract the access token from the response
    $responseData = json_decode($response, true);
    $accessToken = $responseData['access_token'];

    // Store the access token in the session
    $_SESSION['access_token'] = $accessToken;

    // Fetch the user's Twitch username and profile image URL
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
        // Handle non-successful HTTP response
        echo 'HTTP error: ' . $httpCode;
        exit;
    }

    curl_close($curl);

    $userInfo = json_decode($userInfoResponse, true);

    if (isset($userInfo['data']) && count($userInfo['data']) > 0) {
        $twitchUsername = $userInfo['data'][0]['login'];
        $twitchDisplayName = $userInfo['data'][0]['display_name'];
        $profileImageUrl = $userInfo['data'][0]['profile_image_url'];

        // Store the Twitch username and profile image in the session
        $_SESSION['twitch_username'] = $twitchUsername;
        $_SESSION['twitch_display_name'] = $twitchDisplayName;
        $_SESSION['profile_image_url'] = $profileImageUrl;

        // Redirect the user to the index page
        header('Location: index.php');
        exit;
    } else {
        // Failed to fetch user information from Twitch
        echo "Failed to fetch user information from Twitch.";
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>BotOfTheSpecter - Twitch Login</title>
</head>
<body>
    <p>Please wait while we redirect you to Twitch for authorization.</p>
</body>
</html>
