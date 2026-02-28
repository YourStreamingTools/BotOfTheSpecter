<?php
session_start();
require_once "/var/www/config/db_connect.php";

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$isLoggedIn = isset($_SESSION['access_token']) && isset($_SESSION['api_key']);

$pageTitle = 'Discord Twitch Link';
$statusClass = 'is-danger';
$statusTitle = 'Link Failed';
$statusMessage = 'Invalid request.';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
$originDomain = $_SERVER['HTTP_HOST'];
$cleanReturnUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/discord_twitch_link.php';
if ($token !== '') {
    $cleanReturnUrl .= '?token=' . urlencode($token);
}

$streamersconnectBase = 'https://streamersconnect.com/';
$scopes = 'user:read:email';
$authorizeUrl = $streamersconnectBase . '?' . http_build_query([
    'service' => 'twitch',
    'login' => $originDomain,
    'scopes' => $scopes,
    'return_url' => $cleanReturnUrl
]);

// Handle StreamersConnect auth_data callback (same pattern as home/feedback.php)
if (isset($_GET['auth_data']) || isset($_GET['auth_data_sig']) || isset($_GET['server_token'])) {
    $decoded = null;
    $cfg = require_once "/var/www/config/main.php";
    $streamersConnectApiKey = isset($cfg['streamersconnect_api_key']) ? $cfg['streamersconnect_api_key'] : '';
    if (isset($_GET['auth_data_sig']) && $streamersConnectApiKey) {
        $sig = $_GET['auth_data_sig'];
        $ch = curl_init('https://streamersconnect.com/verify_auth_sig.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['auth_data_sig' => $sig]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $streamersConnectApiKey]);
        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response && $http === 200) {
            $res = json_decode($response, true);
            if (!empty($res['success']) && !empty($res['payload'])) {
                $decoded = $res['payload'];
            }
        }
    }
    if (!$decoded && isset($_GET['server_token']) && $streamersConnectApiKey) {
        $serverToken = $_GET['server_token'];
        $ch = curl_init('https://streamersconnect.com/token_exchange.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['server_token' => $serverToken]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $streamersConnectApiKey]);
        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response && $http === 200) {
            $res = json_decode($response, true);
            if (!empty($res['success']) && !empty($res['payload'])) {
                $decoded = $res['payload'];
            }
        }
    }
    if (!$decoded && isset($_GET['auth_data'])) {
        $decoded = json_decode(base64_decode($_GET['auth_data']), true);
    }
    if (!is_array($decoded) || empty($decoded['success'])) {
        $statusClass = 'is-danger';
        $statusTitle = 'Login Failed';
        $statusMessage = 'Authentication failed or was cancelled.';
    } elseif (isset($decoded['service']) && $decoded['service'] === 'twitch') {
        $user = $decoded['user'] ?? [];
        $_SESSION['twitch_user_id'] = $user['id'] ?? null;
        $_SESSION['twitch_display_name'] = $user['display_name'] ?? ($user['global_name'] ?? ($user['login'] ?? $user['username'] ?? null));
        $_SESSION['twitch_username'] = $user['login'] ?? $user['username'] ?? null;
        $_SESSION['profile_image'] = $user['profile_image_url'] ?? null;
        $_SESSION['user_email'] = $user['email'] ?? null;
        $_SESSION['access_token'] = $decoded['access_token'] ?? null;
        $_SESSION['refresh_token'] = $decoded['refresh_token'] ?? null;
        $twitchUserId = $_SESSION['twitch_user_id'] ?? null;
        if (!empty($twitchUserId) && isset($conn)) {
            $stmt = $conn->prepare("SELECT id, api_key FROM users WHERE twitch_user_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $twitchUserId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['api_key'] = $row['api_key'];
                }
                $stmt->close();
            }
        }
        // Redirect to clean URL (drop auth_data params)
        header('Location: ' . $cleanReturnUrl);
        exit;
    }
}

$existingLinkedName = null;
if ($isLoggedIn && isset($conn)) {
    $sessionTwitchUserId = $_SESSION['twitch_user_id'] ?? null;
    if (!empty($sessionTwitchUserId)) {
        $stmt = $conn->prepare("SELECT twitch_username FROM discord_twitch_links WHERE twitch_user_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $sessionTwitchUserId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $existingLinkedName = $row['twitch_username'] ?? ($_SESSION['twitch_display_name'] ?? 'your Twitch account');
            }
            $stmt->close();
        }
    }
}

ob_start();

if (!$isLoggedIn && $token === '') {
    $statusMessage = 'The link token is missing. Please run !linktwitch again in Discord.';
} elseif (!$isLoggedIn) {
    $statusClass = 'is-warning';
    $statusTitle = 'Login Required';
    $statusMessage = 'Please sign in with Twitch to continue. You will be returned here automatically.';
} elseif ($existingLinkedName !== null) {
    $statusClass = 'is-success';
    $statusTitle = 'Link Complete';
    $linkedName = htmlspecialchars((string)$existingLinkedName, ENT_QUOTES, 'UTF-8');
    $statusMessage = 'Your Discord user is linked to Twitch account: <strong>' . $linkedName . '</strong>.';
} else {
    $apiKey = (string)$_SESSION['api_key'];
    $endpoint = 'https://api.botofthespecter.com/discord/twitch-link/confirm?' . http_build_query([
        'api_key' => $apiKey,
        'token' => $token,
    ]);
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) {
        $statusMessage = 'Unable to reach the API right now. Please try again in a moment. ' . htmlspecialchars($curlError, ENT_QUOTES, 'UTF-8');
    } else {
        $json = json_decode($response, true);
        if ($httpCode === 200 && is_array($json) && !empty($json['success'])) {
            $statusClass = 'is-success';
            $statusTitle = 'Link Complete';
            $linkedName = htmlspecialchars((string)($json['twitch_username'] ?? 'your Twitch account'), ENT_QUOTES, 'UTF-8');
            $statusMessage = 'Your Discord user is now linked to Twitch account: <strong>' . $linkedName . '</strong>.';
        } else {
            $detail = 'Unknown error.';
            if (is_array($json) && isset($json['detail'])) {
                $detail = (string)$json['detail'];
            }
            $statusMessage = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
        }
    }
}
?>
<main class="is-fullwidth content" role="main" aria-labelledby="discord-twitch-link-heading">
    <div class="columns is-centered mt-5">
        <div class="column is-10-tablet is-8-desktop is-7-widescreen">
            <div class="box has-background-dark has-text-light">
                <h1 id="discord-twitch-link-heading" class="title has-text-light"><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
                <article class="message <?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="message-header">
                        <p><?php echo htmlspecialchars($statusTitle, ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <div class="message-body">
                        <?php echo $statusMessage; ?>
                    </div>
                </article>
            </div>
        </div>
    </div>
</main>
<?php
$pageContent = ob_get_clean();
include 'layout.php';
?>