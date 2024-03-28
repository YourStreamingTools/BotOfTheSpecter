<?php
$clientID = ''; // CHANGE TO MAKE THIS WORK
$clientSecret = ''; // CHANGE TO MAKE THIS WORK
$redirectURI = ''; // CHANGE TO MAKE THIS WORK

// Initialize the session
session_start();

// Connect to database
require_once 'db_connect.php';

// Function to perform HTTP requests
function performHttp($url, $method = 'GET', $headers = [], $postData = null) {
    $contextOptions = [
        'http' => [
            'method' => $method,
            'header' => $headers
        ]
    ];
    if ($method === 'POST' && $postData !== null) {
        $contextOptions['http']['content'] = http_build_query($postData);
    }
    $context = stream_context_create($contextOptions);
    return file_get_contents($url, false, $context);
}

function checkUserStatus($conn, $username) {
    // Check if the username exists in the "user" table
    $stmt = $conn->prepare("SELECT COUNT(*) FROM user WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    if ($count > 0) {
        return 'user';
    }
    
    // Check if the username exists in the "interested" table
    $stmt = $conn->prepare("SELECT COUNT(*) FROM interested WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    if ($count > 0) {
        return 'interested';
    }
    
    return 'none'; // Username not found in any table
}

$userStatus = null;
$redirectDelay = 3;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_interest'])) {
    $username = $_POST['username'];
    $email = $_POST['email'];
    $date = date("Y-m-d H:i:s");
    
    // Insert the user's interest into the database
    $stmt = $conn->prepare("INSERT INTO interested (username, email, date) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $email, $date);
    $stmt->execute();
    $stmt->close();
    
    $interestMessage = "Thank you for registering your interest to use the bot!";
} elseif (!isset($_GET['code']) && !isset($_SESSION['access_token'])) {
    // Redirect to Twitch for authentication
    $authURL = "https://id.twitch.tv/oauth2/authorize?response_type=code&client_id={$clientID}&redirect_uri={$redirectURI}&scope=user:read:email";
    header("Location: $authURL");
    exit;
} elseif (isset($_GET['code'])) {
    // Handle the redirect with the authorization code
    $tokenURL = "https://id.twitch.tv/oauth2/token";
    $postData = [
        'client_id' => $clientID,
        'client_secret' => $clientSecret,
        'code' => $_GET['code'],
        'grant_type' => 'authorization_code',
        'redirect_uri' => $redirectURI,
    ];
    $response = performHttp($tokenURL, 'POST', ["Content-Type: application/x-www-form-urlencoded\r\n"], $postData);
    $responseData = json_decode($response, true);
    $_SESSION['access_token'] = $responseData['access_token'];
}

if (isset($_SESSION['access_token'])) {
    // Fetch user information
    $userInfoURL = "https://api.twitch.tv/helix/users";
    $headers = [
        "Authorization: Bearer ".$_SESSION['access_token'],
        "Client-ID: $clientID"
    ];
    $response = performHttp($userInfoURL, 'GET', $headers);
    $userData = json_decode($response, true);

    if (!empty($userData['data'])) {
        $username = $userData['data'][0]['login'];
        $email = $userData['data'][0]['email'];
        
        // Database check for user status
        $userStatus = checkUserStatus($conn, $username);
        
        if ($userStatus === 'user') {
            $redirectMessage = "You are already a registered user. Redirecting to dashboard in $redirectDelay seconds...";
            header("refresh:$redirectDelay;url=https://dashboard.botofthespecter.com");
            exit;
        } elseif ($userStatus === 'interested') {
            $interestMessage = "You have already registered your interest to use the bot.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter - Expression of Interest</title>
    <link rel="stylesheet" type="text/css" href="style.css">
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
</head>
<body>
    <header>
        <h1>BotOfTheSpecter</h1>
    </header>
    <div class="container">
        <?php if (isset($redirectMessage)): ?>
            <p><?php echo $redirectMessage; ?></p>
        <?php elseif (isset($interestMessage)): ?>
            <p><?php echo $interestMessage; ?></p>
        <?php else: ?>
            <form method="post">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" readonly><br><br>
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" readonly><br><br>
                <input type="submit" name="register_interest" value="Register Interest">
            </form>
        <?php endif; ?>
    </div>
    <footer>
        &copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter - All Rights Reserved. | Server 2
    </footer>
</body>
</html>