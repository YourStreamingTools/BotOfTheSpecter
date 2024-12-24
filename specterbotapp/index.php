<?php
session_start();

$host = $_SERVER['HTTP_HOST'];
$clientId = '';
$clientSecret = '';
$redirectUri = 'https://' . $host . '/index.php';

// Twitch OAuth API URLs
$oauthTokenUrl = 'https://id.twitch.tv/oauth2/token';
$authUrl = 'https://id.twitch.tv/oauth2/authorize';

// Handle Twitch OAuth Code
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $oauthTokenUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => $redirectUri,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $tokenData = json_decode($response, true);
    if (isset($tokenData['access_token'])) {
        $_SESSION['access_token'] = $tokenData['access_token'];
        header('Location: /');
        exit();
    }
}

// Check if the user has an access token in the session
if (isset($_SESSION['access_token'])) {
    $accessToken = $_SESSION['access_token'];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.twitch.tv/helix/users');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Client-ID: ' . $clientId,
        'Authorization: Bearer ' . $accessToken,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $userData = json_decode($response, true);
    if (isset($userData['data'][0])) {
        $username = $userData['data'][0]['username'];
    } else {
        $username = 'username';
    }
} else {
    $username = 'username';
}

$authUrl = $authUrl . '?client_id=' . $clientId . '&redirect_uri=' . urlencode($redirectUri) . '&response_type=code&scope=user:read:email';
if (!isset($_SESSION['access_token'])) {
    header('Location: ' . $authUrl);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to SpecterBot Custom API</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.0/css/bulma.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="css/custom.css">
</head>
<body class="dark-mode">
    <section class="hero is-dark">
        <div class="hero-body">
            <div class="container">
                <h1 class="title">Welcome to SpecterBot Custom API</h1>
                <h2 class="subtitle">Your gateway to building custom integrations with SpecterBot.</h2>
            </div>
        </div>
    </section>
    <section class="section">
        <div class="container">
            <h2 class="title">Getting Started</h2>
            <p>
                Welcome to the SpecterBot Custom API!<br>
                This platform allows developers to integrate seamlessly with our service.<br>
                Use your personalized subdomain at <code><?php echo $username; ?>.specterbot.app</code> to interact with your custom endpoints.
            </p>
            <br>
            <div class="box">
                <h3 class="title is-4">Key Features</h3>
                <ul>
                    <li>Custom subdomains for users</li>
                </ul>
            </div>
        </div>
    </section>
    <footer class="footer">
        <div class="content has-text-centered">
            <p>&copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter - All Rights Reserved.</p>
        </div>
    </footer>
</body>
</html>