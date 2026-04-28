<?php
// home/sso.php
// ----------------------------------------------------------------
// Generalized SSO handoff issuer. Consumers redirect users here
// with ?target=<name>&return=<url-or-path>. We verify the user is
// signed in to home (the .botofthespecter.com cookie), mint a
// single-use token in handoff_tokens with the target bound, and
// redirect the user to the consumer's callback URL with the token
// on the query string.
//
// Flow:
//   consumer (e.g. stream.py at .video region)
//     ──not logged in──▶ /sso.php?target=rtmp-sydney&return=/recordings
//   home/sso.php
//     ──not signed in──▶ /login.php?return=<self>   (then back here after StreamersConnect)
//     ──signed in──▶    INSERT handoff_tokens (target='rtmp-sydney', expires_at=+5min)
//                       302 to https://au-east-1.botofthespecter.video:8080/sso/login?handoff=<token>&return=/recordings
//   consumer
//     verifies token (target match, used=0, expires_at>now), marks used,
//     creates its own session cookie scoped to its domain.
// ----------------------------------------------------------------

require_once '/var/www/lib/session_bootstrap.php';

// Whitelist of accepted SSO targets and their callback URLs.
// Adding a new consumer = adding a row here.
$BOTS_SSO_TARGETS = [
    'support'         => 'https://support.botofthespecter.com/login.php',
    'members'         => 'https://members.botofthespecter.com/login.php',
    'rtmp-sydney'     => 'https://au-east-1.botofthespecter.video/sso/login',
    'rtmp-us-east'    => 'https://us-east-1.botofthespecter.video/sso/login',
    'rtmp-us-west'    => 'https://us-west-1.botofthespecter.video/sso/login',
    'rtmp-eu-central' => 'https://eu-central-1.botofthespecter.video/sso/login',
];

$target = isset($_GET['target']) ? (string)$_GET['target'] : '';
$return = isset($_GET['return']) ? (string)$_GET['return'] : '';

if (!isset($BOTS_SSO_TARGETS[$target])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unknown SSO target.\n";
    exit;
}
$callbackUrl = $BOTS_SSO_TARGETS[$target];

// Not signed in → bounce to login first; come back here once authenticated.
if (empty($_SESSION['access_token']) || empty($_SESSION['twitchUserId'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $self   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'botofthespecter.com')
            . $_SERVER['REQUEST_URI'];
    header('Location: /login.php?return=' . urlencode($self));
    exit;
}

// Sanitize the return URL: allow either a relative path on the consumer site,
// or an absolute URL whose host matches the target's callback host. Anything
// else gets dropped so we can't be used as an open redirect.
$safeReturn = '';
if ($return !== '') {
    // Local path on the consumer ("/", "/recordings", "/foo?bar=1") — must
    // start with one slash, never two (which would be a protocol-relative URL).
    if (strncmp($return, '/', 1) === 0 && strncmp($return, '//', 2) !== 0) {
        $safeReturn = $return;
    } else {
        $rParts = @parse_url($return);
        $cParts = @parse_url($callbackUrl);
        if ($rParts && $cParts
            && !empty($rParts['host']) && !empty($cParts['host'])
            && strcasecmp($rParts['host'], $cParts['host']) === 0
            && (empty($rParts['scheme']) || in_array(strtolower($rParts['scheme']), ['http', 'https'], true))
        ) {
            $safeReturn = $return;
        }
    }
}

// Mint the token.
$token = bin2hex(random_bytes(32));

$twitchUserId = (string)($_SESSION['twitchUserId']  ?? '');
$username     = (string)($_SESSION['username']      ?? '');
$displayName  = (string)($_SESSION['display_name']  ?? '');
$accessToken  = (string)($_SESSION['access_token']  ?? '');
$refreshToken = (string)($_SESSION['refresh_token'] ?? '');
$profileImage = (string)($_SESSION['profile_image'] ?? '');
$isAdmin      = (int)   ($_SESSION['is_admin']      ?? 0);

$stmt = $bots_session_db->prepare(
    "INSERT INTO handoff_tokens
        (token, twitch_user_id, username, display_name, access_token, refresh_token,
         profile_image, is_admin, target, expires_at, used)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0)"
);

if (!$stmt) {
    error_log('[home/sso.php] prepare failed: ' . $bots_session_db->error);
    http_response_code(500);
    echo 'Server error.';
    exit;
}

$stmt->bind_param(
    'sssssssis',
    $token,
    $twitchUserId,
    $username,
    $displayName,
    $accessToken,
    $refreshToken,
    $profileImage,
    $isAdmin,
    $target
);

if (!$stmt->execute()) {
    error_log('[home/sso.php] execute failed: ' . $stmt->error);
    $stmt->close();
    http_response_code(500);
    echo 'Server error.';
    exit;
}
$stmt->close();

// Redirect to the consumer with the token. Preserve return path if safe.
$query = ['handoff' => $token];
if ($safeReturn !== '') {
    $query['return'] = $safeReturn;
}
header('Location: ' . $callbackUrl . '?' . http_build_query($query));
exit;
