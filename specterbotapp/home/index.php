<?php
// Start the session
session_start();

// Database connection
require 'database.php';

// Twitch OAuth API credentials
$clientId = '';
$clientSecret = '';
$redirectUri = 'https://specterbot.app/index.php';

// Twitch OAuth API URLs
$tokenURL = 'https://id.twitch.tv/oauth2/token';
$authUrl = 'https://id.twitch.tv/oauth2/authorize';

$userDatabaseExists = "";
function userDatabaseExists($username) {
    global $conn;
    $stmt = $conn->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    if (!$stmt) {
        die('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count > 0;
}

if (isset($_GET['code'])) {
    $code = $_GET['code'];
    // Exchange the authorization code for an access token and refresh token
    $postData = array(
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => $redirectUri
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
    $_SESSION['access_token'] = $accessToken;
    // Fetch the user's Twitch username
    $userInfoURL = 'https://api.twitch.tv/helix/users';
    $curl = curl_init($userInfoURL);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Client-ID: ' . $clientId
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $userInfoResponse = curl_exec($curl);
    if ($userInfoResponse === false) {
        echo 'cURL error: ' . curl_error($curl);
        exit;
    }
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        echo 'HTTP error: ' . $httpCode;
        exit;
    }
    curl_close($curl);
    $userInfo = json_decode($userInfoResponse, true);
    if (isset($userInfo['data']) && count($userInfo['data']) > 0) {
        $twitchUsername = $userInfo['data'][0]['login'];
        $_SESSION['twitch_username'] = $twitchUsername;
        $userFolder = '/var/www/specterbotapp/' . $twitchUsername;
        if (!userDatabaseExists($twitchUsername)) {
            $userDatabaseExists = "User database does not exist. Please use the bot to create your database first.";
        }
        if (!is_dir($userFolder)) {
            mkdir($userFolder, 0775, true);
        }
        header('Location: ' . strtok($redirectUri, '?'));
        exit;
    } else {
        $twitchUsername = 'guest_user';
        $userDatabaseExists = "User is not signed in";
    }
} else {
    $twitchUsername = isset($_SESSION['twitch_username']) ? $_SESSION['twitch_username'] : 'guest_user';
    $userDatabaseExists = userDatabaseExists($twitchUsername) ? "User database exists" : "User database does not exist";
}

$loginURL = $authUrl . '?client_id=' . $clientId . '&redirect_uri=' . urlencode($redirectUri) . '&response_type=code&scope=user:read:email';
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
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@Tools4Streaming" />
    <meta name="twitter:title" content="BotOfTheSpecter" />
    <meta name="twitter:description" content="BotOfTheSpecter is a powerful bot system designed to enhance your Twitch and Discord experiences, offering dedicated tools for community interaction, channel management, and analytics." />
    <meta name="twitter:image" content="https://cdn.botofthespecter.com/BotOfTheSpecter.jpeg" />
    <link rel="stylesheet" href="css/custom.css">
</head>
<body class="dark-mode">
    <section class="hero is-dark">
        <div class="hero-body">
            <div class="container">
                <div class="columns is-vcentered">
                    <div class="column">
                        <h1 class="title">Welcome to SpecterBot Custom API</h1>
                        <h2 class="subtitle">Your gateway to building custom integrations with SpecterBot.</h2>
                    </div>
                    <div class="column has-text-right">
                        <?php if (!isset($_SESSION['access_token'])): ?>
                            <a href="<?php echo filter_var($loginURL, FILTER_SANITIZE_URL); ?>" class="button is-primary">Login with Twitch</a>
                        <?php endif; ?>
                        <?php if (isset($_SESSION['access_token'])): ?>
                            <a href="logout.php" class="button is-danger">Logout</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <section class="section">
        <div class="container">
            <h2 class="title">Getting Started</h2>
            <p>
                Welcome to the SpecterBot Custom API!<br>
                This platform allows developers to integrate seamlessly with our service.<br>
                Use your personalized subdomain at <code><?php echo $twitchUsername; ?>.specterbot.app</code> to interact with your custom endpoints.<br>
                Please note that you need to sign in to verify your database connection with SpecterBot.
            </p>
            <br>
            <div class="box">
                <h3 class="title is-4">Key Features</h3>
                <ul>
                    <li>Custom subdomains for users</li>
                    <li>Direct Access to your own database that Specter uses. You can access it via <code>https://specterbot.app/database.php</code>.</li>
                </ul>
            </div>
        </div>
    </section>
    <footer class="footer">
        <div class="content has-text-centered">
            <p>&copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter - All Rights Reserved.</p>
        </div>
    </footer>

<script>
    const userDatabaseStatus = "<?php echo $userDatabaseExists; ?>";
    if (userDatabaseStatus === "User database does not exist. Please use the bot to create your database first.") {
        alert("User database does not exist. Please use the bot to create your database first.");
    }
</script>
<script>console.log('Welcome to SpecterBot Custom API!');</script>
<script>console.log('Connection status: <?php echo $connection; ?>');</script>
<script>console.log('Your Twitch username is: <?php echo $twitchUsername; ?>');</script>
<script>console.log('User database status: <?php echo $userDatabaseExists; ?>');</script>
</body>
</html>