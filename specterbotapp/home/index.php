<?php
session_start();

$host = $_SERVER['HTTP_HOST'];
$clientId = '';
$clientSecret = '';
$redirectUri = 'https://specterbot.app/index.php';

// Twitch OAuth API URLs
$oauthTokenUrl = 'https://id.twitch.tv/oauth2/token';
$authUrl = 'https://id.twitch.tv/oauth2/authorize';

// Function to handle cURL requests
function makeApiRequest($url, $headers = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("API cURL error: " . curl_error($ch));
        return null;
    }
    curl_close($ch);
    return json_decode($response, true);
}

// Check if the user has an access token in the session
if (isset($_SESSION['access_token'])) {
    $accessToken = $_SESSION['access_token'];
    $headers = [
        'Client-ID: ' . $clientId,
        'Authorization: Bearer ' . $accessToken,
    ];
    $userData = makeApiRequest('https://api.twitch.tv/helix/users', $headers);
    if (isset($userData['data'][0])) {
        $username = $userData['data'][0]['username'];
    } else {
        $username = 'guest_user';
    }
} else {
    $username = 'guest_user';
}

$authUrl = $authUrl . '?client_id=' . $clientId . '&redirect_uri=' . urlencode($redirectUri) . '&response_type=code&scope=user:read:email';
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
            <?php if (!isset($_SESSION['access_token'])): ?>
                <a href="<?php echo filter_var($authUrl, FILTER_SANITIZE_URL); ?>" class="button is-primary">Login with Twitch</a>
            <?php endif; ?>
            <?php if (isset($_SESSION['access_token'])): ?>
                <a href="logout.php" class="button is-danger">Logout</a>
            <?php endif; ?>
        </div>
    </section>
    <footer class="footer">
        <div class="content has-text-centered">
            <p>&copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter - All Rights Reserved.</p>
        </div>
    </footer>
</body>
</html>