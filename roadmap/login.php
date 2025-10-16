<?php
session_start();

require_once "../config/twitch.php";
$redirectURI = 'https://roadmap.botofthespecter.com/login.php';

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
    
    if (!isset($responseData['access_token'])) {
        $_SESSION['login_error'] = 'Failed to get access token from Twitch: ' . json_encode($responseData);
    } else {
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
            $_SESSION['twitch_id'] = $userInfo['data'][0]['id'];

            // Check if user is admin in website database
            require_once "/var/www/config/database.php";
            $website_conn = new mysqli($db_servername, $db_username, $db_password, "website");
            if ($website_conn->connect_error) {
                // Website database doesn't exist or user isn't in it, that's okay
                $_SESSION['admin'] = false;
            } else {
                $stmt = $website_conn->prepare("SELECT is_admin FROM users WHERE twitch_user_id = ?");
                $twitch_user_id = $userInfo['data'][0]['id'];
                $stmt->bind_param("s", $twitch_user_id);
                $stmt->execute();
                $stmt->bind_result($is_admin);
                if ($stmt->fetch() && $is_admin == 1) {
                    $_SESSION['admin'] = true;
                } else {
                    $_SESSION['admin'] = false;
                }
                $stmt->close();
                $website_conn->close();
            }

            // Ensure session is saved before redirecting
            session_write_close();
            header('Location: index.php');
            exit;
        } else {
            // Login failed
            $_SESSION['login_error'] = 'Failed to fetch user information from Twitch';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="dist/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-blue-600 to-blue-800 text-white min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="bg-white bg-opacity-10 backdrop-blur-md rounded-lg p-8 shadow-xl">
            <h2 class="text-3xl font-bold mb-6 text-center">Logging in...</h2>
            <p class="text-blue-100 text-center mb-6">You are being redirected to Twitch for authentication.</p>
            <p class="text-blue-100 text-center text-sm mb-6">If you are not redirected automatically, 
            <a href="https://id.twitch.tv/oauth2/authorize?client_id=<?php echo $clientID; ?>&redirect_uri=<?php echo urlencode($redirectURI); ?>&response_type=code&scope=openid%20user:read:email" class="underline font-semibold hover:text-white">click here</a>.</p>
        </div>
        <?php if (isset($_SESSION['login_error'])): ?>
        <div class="mt-6 bg-red-500 bg-opacity-20 backdrop-blur-md rounded-lg p-6 shadow-xl border border-red-400">
            <h3 class="text-xl font-bold mb-3">Login Error</h3>
            <p class="text-red-100 mb-6"><?php echo htmlspecialchars($_SESSION['login_error']); ?></p>
            <a href="index.php" class="inline-block bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg transition-colors duration-200">Back to Roadmap</a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>