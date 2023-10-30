<?php
// Set your Twitch application credentials
$clientID = ''; // CHANGE TO MAKE THIS WORK
$redirectURI = ''; // CHANGE TO MAKE THIS WORK
$clientSecret = ''; // CHANGE TO MAKE THIS WORK
$IDScope = 'openid user:read:email moderator:manage:shoutouts chat:read chat:edit moderation:read moderator:read:followers channel:read:vips channel:read:subscriptions moderator:read:chatters bits:read';
$info = "Please wait while we redirect you to Twitch for authorization.";

// Start PHP session
session_start();

// If the user is already logged in, redirect them to the dashboard page
if (isset($_SESSION['access_token'])) {
    header('Location: bot.php');
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
        $twitchUserId = $userInfo['data'][0]['id'];
        
        // Database connect
        require_once "db_connect.php";

        // Insert/update the access token, profile image URL, user ID, and display name in the 'users' table
        $insertQuery = "INSERT INTO users (username, access_token, api_key, profile_image, twitch_user_id, twitch_display_name, is_admin) VALUES ('$twitchUsername', '$accessToken', '" . bin2hex(random_bytes(16)) . "', '$profileImageUrl', '$twitchUserId', '$twitchDisplayName', 0)
                    ON DUPLICATE KEY UPDATE access_token = '$accessToken', profile_image = '$profileImageUrl', twitch_user_id = '$twitchUserId', twitch_display_name = '$twitchDisplayName', last_login = ?";
        $stmt = mysqli_prepare($conn, $insertQuery);
        $last_login = date('Y-m-d H:i:s');
        mysqli_stmt_bind_param($stmt, 's', $last_login);
        
        if (mysqli_stmt_execute($stmt)) {
            // Redirect the user to the dashboard
            header('Location: bot.php');
            exit;
        } else {
            // Handle the case where the insertion failed
            $info = "Failed to save user information.";
            exit;
        }
    } else {
        // Failed to fetch user information from Twitch
        $info = "Failed to fetch user information from Twitch.";
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>BotOfTheSpecter - Twitch Login</title>
    <link rel="icon" href="https://cdn.yourstreaming.tools/img/logo.jpeg" sizes="32x32" />
    <link rel="icon" href="https://cdn.yourstreaming.tools/img/logo.jpeg" sizes="192x192" />
    <link rel="apple-touch-icon" href="https://cdn.yourstreaming.tools/img/logo.jpeg" />
    <meta name="msapplication-TileImage" content="https://cdn.yourstreaming.tools/img/logo.jpeg" />
</head>
<body><?php echo "<p>$info</p></body></html>";?>