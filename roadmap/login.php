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
    <title>Login - BotOfTheSpecter Roadmap</title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/custom.css">
</head>
<body class="login-page">
    <div class="container" style="max-width: 500px;">
        <div class="login-container p-6">
            <?php if (isset($_SESSION['login_error'])): ?>
                <div class="notification is-danger">
                    <button class="delete"></button>
                    <h4 class="title is-5">Login Error</h4>
                    <p><?php echo htmlspecialchars($_SESSION['login_error']); ?></p>
                    <div class="mt-5">
                        <a href="index.php" class="button is-info">Back to Roadmap</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="has-text-centered mb-6">
                    <figure class="image is-96 is-inline-block mb-4">
                        <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter Logo" style="border-radius: 50%; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);">
                    </figure>
                </div>
                <h2 class="title is-3 has-text-centered">BotOfTheSpecter Roadmap</h2>
                <p class="subtitle is-6 has-text-centered mb-6">Sign in with your Twitch account</p>
                <div class="box has-background-info-light mb-5">
                    <p class="icon-text">
                        <span class="icon">
                            <i class="fas fa-info-circle"></i>
                        </span>
                        <span>Redirecting to Twitch for authentication...</span>
                    </p>
                </div>
                <div class="has-text-centered">
                    <p class="mb-3">If you are not redirected automatically,</p>
                    <a href="https://id.twitch.tv/oauth2/authorize?client_id=<?php echo htmlspecialchars($clientID); ?>&redirect_uri=<?php echo urlencode($redirectURI); ?>&response_type=code&scope=openid%20user:read:email" class="button is-primary is-large is-fullwidth">
                        <span class="icon">
                            <i class="fab fa-twitch"></i>
                        </span>
                        <span>Login with Twitch</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        // Close notification when delete button is clicked
        document.addEventListener('DOMContentLoaded', () => {
            (document.querySelectorAll('.notification .delete') || []).forEach(($delete) => {
                const $notification = $delete.parentNode;
                $delete.addEventListener('click', () => {
                    $notification.parentNode.removeChild($notification);
                });
            });
        });
    </script>
</body>
</html>