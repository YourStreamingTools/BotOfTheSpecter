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

function verifyBot($conn, $twitchUserId, $twitchLogin, $access_token, $refresh_token, $validateData, $tokenData, $client_id, $client_secret, &$message, &$error) {
    $expiresIn    = $validateData['expires_in'] ?? $tokenData['expires_in'] ?? null;
    $tokenExpires = $expiresIn !== null ? date('Y-m-d H:i:s', time() + intval($expiresIn)) : null;
    $customBotsUpdated = false;
    $hasRefreshColumn  = false;
    try {
        $colCheck = $conn->query("SHOW COLUMNS FROM custom_bots LIKE 'refresh_token'");
        if ($colCheck && $colCheck->num_rows > 0) { $hasRefreshColumn = true; }
    } catch (Exception $ex) { /* ignore */ }
    if ($hasRefreshColumn) {
        $stmt = $conn->prepare('UPDATE custom_bots SET is_verified = 1, access_token = ?, token_expires = ?, refresh_token = ? WHERE bot_channel_id = ? LIMIT 1');
        if ($stmt) { $stmt->bind_param('ssss', $access_token, $tokenExpires, $refresh_token, $twitchUserId); }
    } else {
        $stmt = $conn->prepare('UPDATE custom_bots SET is_verified = 1, access_token = ?, token_expires = ? WHERE bot_channel_id = ? LIMIT 1');
        if ($stmt) { $stmt->bind_param('sss', $access_token, $tokenExpires, $twitchUserId); }
    }
    if ($stmt) { $stmt->execute(); $customBotsUpdated = $stmt->affected_rows > 0; $stmt->close(); }
    // --- custom_module_bots (always has refresh_token; update ALL rows for this bot across all channels) ---
    $moduleBotsUpdated = false;
    $modStmt = $conn->prepare('UPDATE custom_module_bots SET is_verified = 1, access_token = ?, token_expires = ?, refresh_token = ? WHERE bot_channel_id = ?');
    if ($modStmt) {
        $modStmt->bind_param('ssss', $access_token, $tokenExpires, $refresh_token, $twitchUserId);
        $modStmt->execute();
        $moduleBotsUpdated = $modStmt->affected_rows > 0;
        $modStmt->close();
    }
    if (!$customBotsUpdated && !$moduleBotsUpdated) {
        $error = 'No matching custom bot record found for this Twitch account. Make sure you saved the bot settings in your channel first.';
        return;
    }
    $message = 'Bot verified successfully for Twitch user: ' . e($twitchLogin) . ' (' . e($twitchUserId) . ').';
    // Attempt immediate token rotation to extend validity
    if (!empty($refresh_token) && !empty($client_id) && !empty($client_secret)) {
        try {
            $rch = curl_init('https://id.twitch.tv/oauth2/token');
            curl_setopt($rch, CURLOPT_POST, true);
            curl_setopt($rch, CURLOPT_POSTFIELDS, http_build_query([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
            ]));
            curl_setopt($rch, CURLOPT_RETURNTRANSFER, true);
            $refreshResp = curl_exec($rch);
            $refreshCode = curl_getinfo($rch, CURLINFO_HTTP_CODE);
            curl_close($rch);
            if ($refreshResp !== false && $refreshCode === 200) {
                $refreshData = json_decode($refreshResp, true);
                $newAccess  = $refreshData['access_token'] ?? null;
                $newRefresh = $refreshData['refresh_token'] ?? $refresh_token;
                $newExpires = isset($refreshData['expires_in']) ? date('Y-m-d H:i:s', time() + intval($refreshData['expires_in'])) : $tokenExpires;
                if ($newAccess) {
                    if ($customBotsUpdated) {
                        if ($hasRefreshColumn) {
                            $upd = $conn->prepare('UPDATE custom_bots SET access_token = ?, token_expires = ?, refresh_token = ? WHERE bot_channel_id = ? LIMIT 1');
                            if ($upd) { $upd->bind_param('ssss', $newAccess, $newExpires, $newRefresh, $twitchUserId); $upd->execute(); $upd->close(); }
                        } else {
                            $upd = $conn->prepare('UPDATE custom_bots SET access_token = ?, token_expires = ? WHERE bot_channel_id = ? LIMIT 1');
                            if ($upd) { $upd->bind_param('sss', $newAccess, $newExpires, $twitchUserId); $upd->execute(); $upd->close(); }
                        }
                    }
                    if ($moduleBotsUpdated) {
                        $upd = $conn->prepare('UPDATE custom_module_bots SET access_token = ?, token_expires = ?, refresh_token = ? WHERE bot_channel_id = ?');
                        if ($upd) { $upd->bind_param('ssss', $newAccess, $newExpires, $newRefresh, $twitchUserId); $upd->execute(); $upd->close(); }
                    }
                    $message .= ' (token refreshed)';
                }
            } else {
                error_log('Token refresh failed after verification: HTTP ' . $refreshCode);
            }
        } catch (Exception $ex) {
            error_log('Token refresh exception: ' . $ex->getMessage());
        }
    }
}

$message = '';
$error = '';

// Twitch OAuth settings - expect $clientID, $clientSecret, $redirectURI (or similar) from twitch.php
$client_id = $clientID ?? ($clientID ?? '');
$client_secret = $clientSecret ?? ($clientSecret ?? '');
$redirect_uri = 'https://mybot.specterbot.systems/custombot.php';

// Handle StreamersConnect auth_data callback
if (isset($_GET['auth_data']) || isset($_GET['auth_data_sig']) || isset($_GET['server_token'])) {
    $decoded = null;
    $cfg = require_once "/var/www/config/main.php";
    $apiKey = isset($cfg['streamersconnect_api_key']) ? $cfg['streamersconnect_api_key'] : '';
    // Prefer server-side verification if an API key is configured
    if (isset($_GET['auth_data_sig']) && $apiKey) {
        $sig = $_GET['auth_data_sig'];
        $ch = curl_init('https://streamersconnect.com/verify_auth_sig.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['auth_data_sig' => $sig]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $apiKey]);
        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response && $http === 200) {
            $res = json_decode($response, true);
            if (!empty($res['success']) && !empty($res['payload'])) $decoded = $res['payload'];
        }
    }
    if (!$decoded && isset($_GET['server_token']) && $apiKey) {
        $token = $_GET['server_token'];
        $ch = curl_init('https://streamersconnect.com/token_exchange.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['server_token' => $token]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $apiKey]);
        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response && $http === 200) {
            $res = json_decode($response, true);
            if (!empty($res['success']) && !empty($res['payload'])) $decoded = $res['payload'];
        }
    }
    // Fallback: legacy base64 auth_data
    if (!$decoded && isset($_GET['auth_data'])) {
        $decoded = json_decode(base64_decode($_GET['auth_data']), true);
    }
    if (!is_array($decoded) || empty($decoded['success'])) {
        $error = 'Authentication failed or was cancelled.';
    } elseif (isset($decoded['service']) && $decoded['service'] === 'twitch') {
        $access_token = $decoded['access_token'] ?? null;
        $refresh_token = $decoded['refresh_token'] ?? null;
        $tokenData = $decoded; // for compatibility with later code
        if (empty($access_token)) {
            $error = 'No access token returned from StreamersConnect.';
        } else {
            // Validate the access token
            $validateCh = curl_init('https://id.twitch.tv/oauth2/validate');
            curl_setopt($validateCh, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($validateCh, CURLOPT_HTTPHEADER, [
                'Authorization: OAuth ' . $access_token,
            ]);
            $validateResp = curl_exec($validateCh);
            $validateCode = curl_getinfo($validateCh, CURLINFO_HTTP_CODE);
            $validateErr = curl_error($validateCh);
            curl_close($validateCh);
            if ($validateResp === false || $validateCode !== 200) {
                $error = 'Token validation failed: ' . ($validateErr ?: "HTTP {$validateCode}");
            } else {
                $validateData = json_decode($validateResp, true);
                $scopes = $validateData['scopes'] ?? [];                $required = ['chat:read', 'chat:edit', 'user:write:chat', 'user:bot', 'moderator:read:chatters'];
                $missing = array_diff($required, $scopes);
                if (!empty($missing)) {
                    $error = 'The token is missing required scopes: ' . implode(', ', $missing) . '. Please authorize with chat:read, chat:edit, user:write:chat and user:bot.';
                } else {
                    // Fetch user info to get id/login
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
                            // Update custom_bots and custom_module_bots where bot_channel_id matches
                            $conn = $conn ?? null; // from db_connect
                            if (!$conn) {
                                $error = 'Database connection not available.';
                            } else {
                                verifyBot($conn, $twitchUserId, $twitchLogin, $access_token, $refresh_token, $validateData, $decoded, $client_id, $client_secret, $message, $error);
                            }
                        }
                    }
                }
            }
        }
    }
}

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
                // Validate the access token (to get scopes and expires_in)
                $validateCh = curl_init('https://id.twitch.tv/oauth2/validate');
                curl_setopt($validateCh, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($validateCh, CURLOPT_HTTPHEADER, [
                    'Authorization: OAuth ' . $access_token,
                ]);
                $validateResp = curl_exec($validateCh);
                $validateCode = curl_getinfo($validateCh, CURLINFO_HTTP_CODE);
                $validateErr = curl_error($validateCh);
                curl_close($validateCh);
                if ($validateResp === false || $validateCode !== 200) {
                    $error = 'Token validation failed: ' . ($validateErr ?: "HTTP {$validateCode}");
                } else {
                    $validateData = json_decode($validateResp, true);
                    $scopes = $validateData['scopes'] ?? [];
                    // Ensure the token has the required IRC/chat and bot scopes
                    // user:write:chat and user:bot are required for sending messages as a bot
                    $required = ['chat:read', 'chat:edit', 'user:write:chat', 'user:bot', 'moderator:read:chatters'];
                    $missing = array_diff($required, $scopes);
                    if (!empty($missing)) {
                        $error = 'The token is missing required scopes: ' . implode(', ', $missing) . '. Please authorize with chat:read, chat:edit, user:write:chat and user:bot.';
                    } else {
                        // Token scopes are acceptable - fetch user info to get id/login
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
                                // Update custom_bots and custom_module_bots where bot_channel_id matches
                                $conn = $conn ?? null; // from db_connect
                                if (!$conn) {
                                    $error = 'Database connection not available.';
                                } else {
                                    $refresh_token = $tokenData['refresh_token'] ?? null;
                                    verifyBot($conn, $twitchUserId, $twitchLogin, $access_token, $refresh_token, $validateData, $tokenData, $client_id, $client_secret, $message, $error);
                                }
                            }
                        }
                    }
                }
            }
    }
    }
}

// If no code param and user clicked sign-in, redirect to StreamersConnect
// Guard: do not redirect if we're already handling a callback (auth_data, code, server_token)
if (isset($_GET['action']) && $_GET['action'] === 'login'
    && !isset($_GET['auth_data']) && !isset($_GET['auth_data_sig'])
    && !isset($_GET['server_token']) && !isset($_GET['code'])) {
    $state = bin2hex(random_bytes(8));
    // Keep a lightweight marker in session (optional) to indicate SC flow
    $_SESSION['sc_twitch_state'] = $state;
    // Request chat scopes so the returned token can be used for IRC/chat actions
    $scope = 'user:read:email chat:read chat:edit user:write:chat user:bot moderator:read:chatters';
    // Build StreamersConnect URL
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
    $originDomain = $_SERVER['HTTP_HOST'];
    // Use only the script path — no query string — so the return URL is clean
    $returnUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
    $streamersconnectBase = 'https://streamersconnect.com/';
    $authUrl = $streamersconnectBase . '?' . http_build_query([
        'service' => 'twitch',
        'login' => $originDomain,
        'scopes' => $scope,
        'return_url' => $returnUrl
    ]);
    header('Location: ' . $authUrl);
    exit();
}
session_write_close();
?>
<!doctype html>
<html lang="en" class="theme-dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BotOfTheSpecter - Verify Custom Bot</title>
    <link rel="stylesheet" href="../css/style.css">
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