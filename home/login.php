<?php
// home/login.php
// ----------------------------------------------------------------
// Identity-provider entry point. botofthespecter.com is now the SSO
// authority; this file owns the StreamersConnect OAuth callback and
// is the destination for any consumer app that needs to log a user in.
//
// Flow:
//   - Already logged in -> redirect to post_login_redirect (or /).
//   - ?return=<url> on the query string is stashed (whitelisted to our
//     own domains) so we can drop the user back where they came from.
//   - StreamersConnect callback (auth_data_sig / server_token /
//     legacy auth_data) -> verify, populate $_SESSION via the shared
//     web_sessions handler, regenerate session id, redirect.
//   - Otherwise -> kick off StreamersConnect with this page as the
//     return URL.
// ----------------------------------------------------------------

require_once '/var/www/lib/session_bootstrap.php';

/**
 * Allow only URLs whose host is botofthespecter.com, *.botofthespecter.com,
 * or *.botofthespecter.video. Anything else returns null (caller falls
 * back to the default redirect).
 */
function bots_sanitize_return_url($url): ?string
{
    if (!is_string($url) || $url === '') return null;
    $parts = @parse_url($url);
    if (!$parts || empty($parts['host'])) return null;
    if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return null;
    }
    $host = strtolower($parts['host']);
    foreach (['botofthespecter.com', 'botofthespecter.video'] as $suffix) {
        if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
            return $url;
        }
    }
    return null;
}

// ----------------------------------------------------------------
// Already authenticated -> go to return URL or home.
// ----------------------------------------------------------------
if (!empty($_SESSION['access_token'])) {
    $dest = bots_sanitize_return_url($_SESSION['post_login_redirect'] ?? null) ?? '/';
    unset($_SESSION['post_login_redirect']);
    header('Location: ' . $dest);
    exit;
}

// ----------------------------------------------------------------
// Stash a sanitized return URL for after login completes.
// ----------------------------------------------------------------
if (!empty($_GET['return'])) {
    $r = bots_sanitize_return_url($_GET['return']);
    if ($r) $_SESSION['post_login_redirect'] = $r;
}

$info     = 'Please wait while we sign you in…';
$hasError = false;

$cfg    = include '/var/www/config/main.php';
$apiKey = $cfg['streamersconnect_api_key'] ?? '';

// ----------------------------------------------------------------
// StreamersConnect OAuth response (mirrors support/login.php).
// ----------------------------------------------------------------
if (isset($_GET['auth_data']) || isset($_GET['auth_data_sig']) || isset($_GET['server_token'])) {
    $decoded = null;
    // Preferred: signature-based verification.
    if (!empty($_GET['auth_data_sig']) && $apiKey) {
        $sig = $_GET['auth_data_sig'];
        $ch  = curl_init('https://streamersconnect.com/verify_auth_sig.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['auth_data_sig' => $sig]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp && $http === 200) {
            $res = json_decode($resp, true);
            if (!empty($res['success']) && !empty($res['payload'])) $decoded = $res['payload'];
        }
    }
    // Fallback: server_token exchange.
    if (!$decoded && !empty($_GET['server_token']) && $apiKey) {
        $tok = $_GET['server_token'];
        $ch  = curl_init('https://streamersconnect.com/token_exchange.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['server_token' => $tok]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-API-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp && $http === 200) {
            $res = json_decode($resp, true);
            if (!empty($res['success']) && !empty($res['payload'])) $decoded = $res['payload'];
        }
    }
    // Legacy: base64-encoded payload.
    if (!$decoded && !empty($_GET['auth_data'])) {
        $decoded = json_decode(base64_decode($_GET['auth_data']), true);
    }
    if (!is_array($decoded) || empty($decoded['success'])) {
        $info     = 'Authentication failed or was cancelled. Please try again.';
        $hasError = true;
    } elseif (($decoded['service'] ?? '') !== 'twitch') {
        $info     = 'Unexpected authentication service. Please try again.';
        $hasError = true;
    } else {
        $user         = $decoded['user']           ?? [];
        $accessToken  = $decoded['access_token']   ?? null;
        $refreshToken = $decoded['refresh_token']  ?? null;
        $expiresIn    = isset($decoded['expires_in']) ? (int)$decoded['expires_in'] : 14400;
        $twitchUserId = $user['id']                ?? null;
        $uname        = $user['login']             ?? ($user['username'] ?? null);
        $dname        = $user['display_name']      ?? ($user['global_name'] ?? $uname);
        $pimg         = $user['profile_image_url'] ?? null;
        if ($accessToken && $twitchUserId) {
            // Look up users row by stable twitch_user_id (NOT access_token —
            // the token rotates every login). If the user exists we UPDATE
            // their tokens/profile so dashboard's userdata.php — which looks
            // up by access_token — finds the row. Without this, you log in
            // here, redirect to dashboard, dashboard's userdata.php sees 0
            // rows for the new token, redirects to login.php, login.php
            // sees session and redirects back to dashboard => redirect loop.
            $isAdmin    = 0;
            $userRowId  = 0;
            $apiKey     = '';
            $userEmail  = (string)($user['email'] ?? '');
            $emailFromDb = '';
            if (isset($bots_session_db) && $bots_session_db instanceof mysqli) {
                $stmt = $bots_session_db->prepare(
                    "SELECT id, api_key, is_admin, email FROM users WHERE twitch_user_id = ? LIMIT 1"
                );
                if ($stmt) {
                    $stmt->bind_param('s', $twitchUserId);
                    $stmt->execute();
                    $stmt->bind_result($userRowId, $apiKey, $isAdmin, $emailFromDb);
                    if (!$stmt->fetch()) {
                        $userRowId   = 0;
                        $apiKey      = '';
                        $isAdmin     = 0;
                        $emailFromDb = '';
                    }
                    $stmt->close();
                }
                // Prefer the email Twitch just gave us if present, else
                // keep what's already in the users table.
                if ($userEmail === '' && $emailFromDb !== '') {
                    $userEmail = (string)$emailFromDb;
                }
                $nowSql = date('Y-m-d H:i:s');
                if ($userRowId > 0) {
                    // Existing user — refresh their tokens + profile.
                    $u = $bots_session_db->prepare(
                        "UPDATE users
                            SET access_token = ?, refresh_token = ?, profile_image = ?,
                                username = ?, twitch_display_name = ?, last_login = ?,
                                email = ?
                          WHERE twitch_user_id = ?"
                    );
                    if ($u) {
                        $u->bind_param('ssssssss',
                            $accessToken, $refreshToken, $pimg,
                            $uname, $dname, $nowSql,
                            $userEmail, $twitchUserId
                        );
                        if (!$u->execute()) {
                            error_log('[home/login.php] users UPDATE failed: ' . $u->error);
                        }
                        $u->close();
                    } else {
                        error_log('[home/login.php] users UPDATE prepare failed: ' . $bots_session_db->error);
                    }
                } else {
                    // Brand-new user — assign an api_key and insert. Reuse the
                    // smallest missing id (matches dashboard/login.php's pattern
                    // so id sequencing stays consistent across login paths).
                    $apiKey = bin2hex(random_bytes(16));
                    $bots_session_db->begin_transaction();
                    try {
                        $assignedId = 1;
                        $idRes = $bots_session_db->query(
                            "SELECT id FROM users ORDER BY id ASC FOR UPDATE"
                        );
                        if ($idRes) {
                            $expected = 1;
                            while ($row = $idRes->fetch_assoc()) {
                                $rid = (int)$row['id'];
                                if ($rid !== $expected) break;
                                $expected++;
                            }
                            $assignedId = $expected;
                            $idRes->free();
                        }
                        $ins = $bots_session_db->prepare(
                            "INSERT INTO users
                                (id, username, access_token, refresh_token, api_key,
                                 profile_image, twitch_user_id, twitch_display_name,
                                 email, is_admin, last_login)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
                        );
                        if (!$ins) {
                            throw new Exception('users INSERT prepare failed: '
                                . $bots_session_db->error);
                        }
                        $ins->bind_param('isssssssss',
                            $assignedId, $uname, $accessToken, $refreshToken, $apiKey,
                            $pimg, $twitchUserId, $dname, $userEmail, $nowSql
                        );
                        if (!$ins->execute()) {
                            $err = $ins->error;
                            $ins->close();
                            throw new Exception('users INSERT execute failed: ' . $err);
                        }
                        $ins->close();
                        @$bots_session_db->query(
                            "ALTER TABLE users AUTO_INCREMENT = " . ($assignedId + 1)
                        );
                        $bots_session_db->commit();
                        $userRowId = $assignedId;
                        $isAdmin   = 0;
                    } catch (Exception $e) {
                        @$bots_session_db->rollback();
                        error_log('[home/login.php] new-user insert failed: ' . $e->getMessage());
                    }
                }
            }
            // Mint a fresh session id post-auth (fixation defense).
            session_regenerate_id(true);
            $_SESSION['access_token']      = $accessToken;
            $_SESSION['refresh_token']     = $refreshToken;
            $_SESSION['twitchUserId']      = $twitchUserId;   // camelCase; bootstrap mirrors snake_case
            $_SESSION['user_id']           = (int)$userRowId;
            $_SESSION['api_key']           = (string)$apiKey;
            $_SESSION['user_email']        = (string)$userEmail;
            $_SESSION['username']          = $uname;
            $_SESSION['display_name']      = $dname;
            $_SESSION['profile_image']     = $pimg;
            $_SESSION['is_admin']          = (int)$isAdmin;
            $_SESSION['twitch_expires_at'] = time() + $expiresIn;
            $_SESSION['last_validated_at'] = time();
            $dest = bots_sanitize_return_url($_SESSION['post_login_redirect'] ?? null) ?? '/';
            unset($_SESSION['post_login_redirect']);
            header('Location: ' . $dest);
            exit;
        }
        $info     = 'Failed to read authentication data. Please try again.';
        $hasError = true;
    }
}

// ----------------------------------------------------------------
// No auth data yet — bounce to StreamersConnect.
// ----------------------------------------------------------------
// Scope list MUST stay in sync with dashboard/login.php's $IDScope.
// home is the SSO authority for the whole platform, so the access_token
// it mints has to satisfy every consumer's Twitch API needs:
//   - dashboard/bot.php -> /helix/moderation/moderators (moderator:read:moderators)
//   - moderation tools  -> moderation:read, channel:manage:moderators, ...
//   - chat features     -> chat:read, chat:edit, user:read:chat, user:write:chat
//   - subs/follows/etc  -> channel:read:subscriptions, moderator:read:followers
// If you trim this, dashboard pages will silently fail their Twitch
// calls and show false-negative states (e.g. "bot is not a moderator").
$BOTS_SSO_SCOPES = 'openid channel:bot moderator:manage:chat_messages user:read:moderated_channels moderator:read:blocked_terms moderator:read:chat_settings moderator:read:vips moderator:read:moderators moderator:read:unban_requests moderator:read:banned_users moderator:read:chat_messages moderator:read:warnings user:bot channel:read:goals channel:moderate channel:manage:moderators user:edit:broadcast channel:manage:redemptions channel:manage:polls moderator:manage:automod moderator:read:suspicious_users channel:read:hype_train channel:manage:broadcast channel:manage:raids channel:read:charity user:read:email user:read:chat user:write:chat user:read:follows chat:read chat:edit moderation:read moderator:read:followers channel:read:redemptions channel:read:vips channel:manage:vips user:read:subscriptions channel:read:subscriptions moderator:read:chatters bits:read channel:manage:ads channel:read:ads channel:manage:schedule channel:manage:clips editor:manage:clips clips:edit moderator:manage:announcements moderator:manage:banned_users moderator:manage:chat_messages moderator:read:shoutouts moderator:manage:shoutouts user:read:blocked_users user:manage:blocked_users';

if (!$hasError && empty($_GET['auth_data']) && empty($_GET['auth_data_sig']) && empty($_GET['server_token'])) {
    $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'] ?? 'botofthespecter.com';
    $returnUrl = $scheme . '://' . $host . '/login.php';
    $authUrl   = 'https://streamersconnect.com/?' . http_build_query([
        'service'    => 'twitch',
        'login'      => $host,
        'scopes'     => $BOTS_SSO_SCOPES,
        'return_url' => $returnUrl,
    ]);
    header('Location: ' . $authUrl);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in &mdash; BotOfTheSpecter</title>
    <link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <link rel="stylesheet" href="https://cdn.botofthespecter.com/css/fontawesome-7.1.0/css/all.css">
</head>
<body style="font-family: ui-sans-serif, system-ui, sans-serif; background:#0e1116; color:#e6edf3; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0;">
    <div style="max-width:420px; padding:32px; background:#161b22; border-radius:8px; text-align:center;">
        <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter" style="width:96px; height:96px; margin-bottom:16px;">
        <h1 style="margin:0 0 12px 0; font-size:20px;">BotOfTheSpecter</h1>
        <p style="color:#8b949e; margin:0 0 16px 0;"><?php echo htmlspecialchars($info); ?></p>
        <?php if ($hasError): ?>
            <a href="/login.php" style="display:inline-block; padding:10px 18px; background:#9146ff; color:#fff; text-decoration:none; border-radius:4px;">
                <i class="fa-brands fa-twitch"></i>&nbsp; Try Again with Twitch
            </a>
        <?php else: ?>
            <p style="color:#8b949e;">Redirecting…</p>
        <?php endif; ?>
    </div>
</body>
</html>
