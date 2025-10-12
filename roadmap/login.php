<?php
require_once "../config/twitch.php";
$redirectURI = 'https://roadmap.botofthespecter.com/login.php';

session_start();

if (isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

if (!isset($_GET['code'])) {
    header('Location: https://id.twitch.tv/oauth2/authorize' .
        '?client_id=' . $clientID .
        '&redirect_uri=' . urlencode($redirectURI) .
        '&response_type=code' .
        '&scope=openid user:read:email');
    exit;
}

if (isset($_GET['code'])) {
    $code = $_GET['code'];
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
    curl_close($curl);
    $responseData = json_decode($response, true);
    $accessToken = $responseData['access_token'];

    // Fetch user info
    $userInfoURL = 'https://api.twitch.tv/helix/users';
    $curl = curl_init($userInfoURL);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Client-ID: ' . $clientID
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $userInfoResponse = curl_exec($curl);
    curl_close($curl);
    $userInfo = json_decode($userInfoResponse, true);
    if (isset($userInfo['data'][0])) {
        $_SESSION['username'] = $userInfo['data'][0]['login'];
        $_SESSION['display_name'] = $userInfo['data'][0]['display_name'];

        // Check if user is admin in website database
        require_once "/var/www/config/database.php";
        $website_conn = new mysqli($db_servername, $db_username, $db_password, "website");
        if ($website_conn->connect_error) {
            die("Connection failed: " . $website_conn->connect_error);
        }
        $stmt = $website_conn->prepare("SELECT is_admin FROM users WHERE twitch_user_id = ?");
        $twitch_user_id = $userInfo['data'][0]['id'];
        $stmt->bind_param("s", $twitch_user_id);
        $stmt->execute();
        $stmt->bind_result($is_admin);
        if ($stmt->fetch() && $is_admin == 1) {
            $_SESSION['admin'] = true;
        }
        $stmt->close();
        $website_conn->close();

        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login</title>
</head>
<body>
    <p>Logging in...</p>
</body>
</html>