<?php
session_start();
// Load DB and Twitch config
require_once '/var/www/config/db_connect.php';
require_once '/var/www/config/twitch.php';

// Enforce canonical host: redirect to mybot.specterbot.systems if accessed via other host
if (isset($_SERVER['HTTP_HOST']) && strtolower($_SERVER['HTTP_HOST']) !== 'mybot.specterbot.systems') {
    $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    $target = 'https://mybot.specterbot.systems' . $_SERVER['REQUEST_URI'] . $qs;
    header('Location: ' . $target, true, 302);
    exit();
}

// Simple helper to render escaped output
function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES); }

$message = '';
$error = '';

// Twitch OAuth settings - expect $clientID, $clientSecret, $redirectURI (or similar) from twitch.php
$client_id = $clientID ?? ($clientID ?? '');
$client_secret = $clientSecret ?? ($clientSecret ?? '');
$redirect_uri = 'https://mybot.specterbot.systems/custombot.php';

// If we're returning from Twitch with ?code=, exchange it
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    // Validate returned state to prevent CSRF
    if (!isset($_GET['state']) || !isset($_SESSION['twitch_oauth_state']) || $_GET['state'] !== $_SESSION['twitch_oauth_state']) {
        $error = 'Invalid OAuth state. Please try again.';
    } else {
        // clear state once used
        unset($_SESSION['twitch_oauth_state']);
    }
    // If state validation set an error, skip exchange
    if (empty($error)) {
    // Exchange code for token
    $tokenUrl = 'https://id.twitch.tv/oauth2/token';
    $post = http_build_query([
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => $redirect_uri,
    ]);
    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    $codeHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $codeHttp !== 200) {
        $error = 'Failed to exchange code for token: ' . ($err ?: "HTTP {$codeHttp}");
    } else {
        $tokenData = json_decode($resp, true);
        $access_token = $tokenData['access_token'] ?? null;
        if (!$access_token) {
            $error = 'No access token returned from Twitch.';
        } else {
            // Get the authenticated user's id
            $ch = curl_init('https://api.twitch.tv/helix/users');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $access_token,
                'Client-ID: ' . $client_id,
            ]);
            $userResp = curl_exec($ch);
            $userCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $userErr = curl_error($ch);
            curl_close($ch);
            if ($userResp === false || $userCode !== 200) {
                $error = 'Failed to fetch Twitch user: ' . ($userErr ?: "HTTP {$userCode}");
            } else {
                $userData = json_decode($userResp, true);
                $twitchUserId = $userData['data'][0]['id'] ?? null;
                $twitchLogin = $userData['data'][0]['login'] ?? null;
                if (!$twitchUserId) {
                    $error = 'Could not determine Twitch user id.';
                } else {
                    // Update custom_bots row where bot_channel_id matches
                    $conn = $conn ?? null; // from db_connect
                    if (!$conn) {
                        $error = 'Database connection not available.';
                    } else {
                        $stmt = $conn->prepare('UPDATE custom_bots SET is_verified = 1 WHERE bot_channel_id = ? LIMIT 1');
                        if ($stmt) {
                            $stmt->bind_param('s', $twitchUserId);
                            if ($stmt->execute() && $stmt->affected_rows > 0) {
                                $message = 'Bot verified successfully for Twitch user: ' . e($twitchLogin) . ' (' . e($twitchUserId) . ').';
                            } else {
                                $error = 'No matching custom bot record found for this Twitch account. Make sure you saved the bot settings in your channel first.';
                            }
                        } else {
                            $error = 'Failed to prepare DB statement: ' . $conn->error;
                        }
                    }
                }
            }
        }
    }
    }

}

// If no code param and user clicked sign-in, redirect to Twitch authorize
if (isset($_GET['action']) && $_GET['action'] === 'login') {
    $state = bin2hex(random_bytes(8));
    $_SESSION['twitch_oauth_state'] = $state;
    $scope = 'user:read:email';
    $authorize = 'https://id.twitch.tv/oauth2/authorize?response_type=code&client_id=' . urlencode($client_id) . '&redirect_uri=' . urlencode($redirect_uri) . '&scope=' . urlencode($scope) . '&state=' . urlencode($state);
    header('Location: ' . $authorize);
    exit();
}
?>
<!doctype html>
<html lang="en" class="theme-dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BotOfTheSpecter - Verify Custom Bot</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.css">
    <style>
        /* Dark mode tweaks */
        html, body { background:#0b0f13; color: #e6edf3; }
        .box { background:#0f1620; color: #e6edf3; }
        .button.is-light { background:#1b2430; color:#e6edf3; }
        a { color: #7ad1ff; }
    </style>
</head>
<body>
    <section class="section">
        <div class="container">
            <div class="columns is-centered">
                <div class="column is-6">
                    <div class="box">
                        <h1 class="title">Verify Custom Bot</h1>
                        <p class="subtitle">Sign in with your bot account to verify ownership.</p>
                        <?php if ($message): ?>
                            <div class="notification is-success">
                                <?php echo e($message); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($error): ?>
                            <div class="notification is-danger">
                                <?php echo e($error); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!$message): ?>
                            <div class="content">
                                <p>To verify a custom bot, sign in using the bot's Twitch account. After signing in, this page will attempt to match the bot's Twitch ID with your saved custom bot settings and mark it as verified.</p>
                                <div style="margin-top:1rem;">
                                    <a class="button is-primary" href="?action=login">Sign in with Twitch</a>
                                    <a class="button is-light" href="https://dashboard.botofthespecter.com" style="margin-left:0.5rem;">Back to Dashboard</a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div style="margin-top:1rem;">
                                <a class="button is-light" href="https://dashboard.botofthespecter.com">Return to Dashboard</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</body>
</html>
