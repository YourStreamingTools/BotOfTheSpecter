<?php
// Set your Twitch application credentials
$clientID = ''; // CHANGE TO MAKE THIS WORK
$redirectURI = ''; // CHANGE TO MAKE THIS WORK
$clientSecret = ''; // CHANGE TO MAKE THIS WORK
$IDScope = 'openid channel:bot channel:moderate user:read:email user:read:chat user:write:chat moderator:manage:shoutouts chat:read chat:edit moderation:read moderator:read:followers channel:read:redemptions channel:read:vips channel:manage:vips channel:read:subscriptions moderator:read:chatters bits:read channel:manage:ads channel:read:ads channel:manage:schedule clips:edit moderator:manage:announcements moderator:manage:banned_users moderator:manage:chat_messages moderator:read:shoutouts moderator:manage:shoutouts user:read:blocked_users user:manage:blocked_users';
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
        // Handle non-successful HTTP response
        echo 'HTTP error: ' . $httpCode;
        exit;
    }

    curl_close($curl);

    // Extract the access token and refresh token from the response
    $responseData = json_decode($response, true);
    $accessToken = $responseData['access_token'];
    $refreshToken = $responseData['refresh_token'];

    // Store the access token and refresh token in the session
    $_SESSION['access_token'] = $accessToken;
    $_SESSION['refresh_token'] = $refreshToken;

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
        $twitchDisplayName = $userInfo['data'][0]['display_name'];
    
        // Read the list of authorized users from the JSON file located in a specific directory
        $authUsersJson = file_get_contents('/var/www/api/authusers.json');
        $authUsers = json_decode($authUsersJson, true)['users'];
    
        // Check if the user is authorized
        if (!in_array($twitchDisplayName, $authUsers)) {
            // Redirect to unauthorized page
            header('Location: unauthorized.php');
            exit;
        }

        $twitchUsername = $userInfo['data'][0]['login'];
        $twitchDisplayName = $userInfo['data'][0]['display_name'];
        $profileImageUrl = $userInfo['data'][0]['profile_image_url'];
        $twitchUserId = $userInfo['data'][0]['id'];
        
        // Database connect
        require_once "db_connect.php";

        function generateUniqueWebHookPort($conn) {
            $basePort = 8000;
            $maxPort = 9000;
        
            // Query to find the maximum webhook port number currently in use
            $query = "SELECT MAX(webhook_port) as max_webhook_port FROM users";
            $result = mysqli_query($conn, $query);
            $row = mysqli_fetch_assoc($result);
        
            $lastPort = $row['max_webhook_port'] ? $row['max_webhook_port'] : $basePort;
            $newPort = $lastPort + 1;
        
            return ($newPort <= $maxPort) ? $newPort : null;
        }
        
        function generateUniqueWebShocketPort($conn) {
            $basePort = 49152;
            $maxPort = 65535;
        
            // Query to find the maximum webshocket port number currently in use
            $query = "SELECT MAX(websocket_port) as max_websocket_port FROM users";
            $result = mysqli_query($conn, $query);
            $row = mysqli_fetch_assoc($result);
        
            $lastPort = $row['max_websocket_port'] ? $row['max_websocket_port'] : $basePort;
            $newPort = $lastPort + 1;
        
            return ($newPort <= $maxPort) ? $newPort : null;
        }        

        // Insert/update user data
        $insertQuery = "INSERT INTO users (username, access_token, refresh_token, api_key, profile_image, twitch_user_id, twitch_display_name, webhook_port, is_admin)
        VALUES ('$twitchUsername', '$accessToken', '$refreshToken', '" . bin2hex(random_bytes(16)) . "', '$profileImageUrl', '$twitchUserId', '$twitchDisplayName', ?, 0)
        ON DUPLICATE KEY UPDATE access_token = '$accessToken', refresh_token = '$refreshToken', profile_image = '$profileImageUrl', twitch_user_id = '$twitchUserId', twitch_display_name = '$twitchDisplayName', last_login = ?";
        
        $stmt = mysqli_prepare($conn, $insertQuery);
        $last_login = date('Y-m-d H:i:s');
        $uniqueWebHookPort = generateUniqueWebHookPort($conn);
        $uniqueWebShocketPort = generateUniqueWebShocketPort($conn);

        // Bind the generated port number for new users, and the last login date
        mysqli_stmt_bind_param($stmt, 'is', $uniqueWebHookPort, $last_login);

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