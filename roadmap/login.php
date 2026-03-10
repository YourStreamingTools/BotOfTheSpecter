<?php
session_start();

require_once "/var/www/config/twitch.php";
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
                    // If user is admin, initialize roadmap database
                    require_once "admin/database.php";
                    initializeRoadmapDatabase();
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
    <title>Login — BotOfTheSpecter Roadmap</title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <div class="rm-login-wrap">
        <div class="rm-login-card">
            <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter" class="rm-login-logo">
            <h1 class="rm-login-title">BotOfTheSpecter Roadmap</h1>
            <p class="rm-login-sub">Sign in with your Twitch account</p>

            <?php if (isset($_SESSION['login_error'])): ?>
                <div class="sp-alert sp-alert-danger" style="width:100%;margin-bottom:1rem;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span><?php echo htmlspecialchars($_SESSION['login_error']); unset($_SESSION['login_error']); ?></span>
                </div>
                <a href="index.php" class="sp-btn sp-btn-secondary" style="width:100%;justify-content:center;">
                    <i class="fa-solid fa-arrow-left"></i> Back to Roadmap
                </a>
            <?php else: ?>
                <div class="sp-alert sp-alert-info" style="width:100%;margin-bottom:1.5rem;">
                    <i class="fa-solid fa-circle-info"></i>
                    Redirecting to Twitch for authentication&hellip;
                </div>
                <a href="https://id.twitch.tv/oauth2/authorize?client_id=<?php echo htmlspecialchars($clientID); ?>&redirect_uri=<?php echo urlencode($redirectURI); ?>&response_type=code&scope=openid%20user:read:email"
                    class="sp-btn sp-btn-primary" style="width:100%;justify-content:center;font-size:1rem;padding:0.75rem 1.5rem;">
                    <i class="fa-brands fa-twitch"></i> Login with Twitch
                </a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>