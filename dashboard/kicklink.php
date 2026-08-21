<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
require_once __DIR__ . '/includes/kick_bot.php';

$kickConfigPath = '/var/www/config/kick.php';
if (!is_file($kickConfigPath)) {
    $kickConfigPath = dirname(__DIR__) . '/config/kick.php';
}
if (is_file($kickConfigPath)) {
    require_once $kickConfigPath;
}

$kickClientId = isset($kick_client_id) ? trim((string)$kick_client_id) : '';
$kickClientSecret = isset($kick_client_secret) ? trim((string)$kick_client_secret) : '';
$kickRedirectUri = isset($kick_redirect_uri) ? trim((string)$kick_redirect_uri) : 'https://dashboard.botofthespecter.com/kicklink.php';

function kicklink_redirect_profile(string $message, string $alertClass): void {
    $_SESSION['profile_message'] = $message;
    $_SESSION['profile_alert_class'] = $alertClass;
    header('Location: profile.php#connections');
    exit();
}

function kicklink_http_json(string $method, string $url, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string)$raw, true);
    return [$code, is_array($decoded) ? $decoded : []];
}

function kicklink_first_item(array $body): array {
    $data = $body['data'] ?? $body;
    if (!is_array($data)) {
        return [];
    }
    if (isset($data[0]) && is_array($data[0])) {
        return $data[0];
    }
    return $data;
}

function kicklink_chatroom_id(string $slug, string $kickUserId): string {
    $urls = [
        'https://kick.com/api/v2/channels/' . rawurlencode($slug),
        'https://kick.com/api/v1/channels/' . rawurlencode($slug),
    ];
    foreach ($urls as $url) {
        [$status, $body] = kicklink_http_json(
            'GET',
            $url,
            ['Accept: application/json', 'User-Agent: BotOfTheSpecter']
        );
        if ($status !== 200) {
            continue;
        }
        $id = $body['chatroom']['id'] ?? $body['chatroom_id'] ?? $body['id'] ?? '';
        $id = trim((string)$id);
        if ($id !== '' && ctype_digit($id)) {
            return $id;
        }
    }
    return trim($kickUserId);
}

if (!empty($isActAs)) {
    kicklink_redirect_profile(t('kick_link_actas_disabled'), 'is-warning');
}

if ($kickClientId === '' || $kickClientSecret === '') {
    kicklink_redirect_profile(t('bot_kick_app_not_configured'), 'is-danger');
}

$twitchLogin = strtolower((string)($username ?? ''));
if ($twitchLogin === '') {
    kicklink_redirect_profile(t('kick_link_failed'), 'is-danger');
}

$kickScopes = 'user:read channel:read channel:write chat:write events:subscribe moderation:ban moderation:chat_message:manage kicks:read';

if (isset($_GET['error'])) {
    kicklink_redirect_profile(t('kick_link_denied'), 'is-warning');
}

if (isset($_GET['code'])) {
    $code = trim((string)$_GET['code']);
    $state = (string)($_GET['state'] ?? '');
    $expectedState = (string)($_SESSION['kick_oauth_state'] ?? '');
    $verifier = (string)($_SESSION['kick_oauth_verifier'] ?? '');
    unset($_SESSION['kick_oauth_state'], $_SESSION['kick_oauth_verifier']);
    if ($code === '' || $state === '' || $expectedState === '' || !hash_equals($expectedState, $state) || $verifier === '') {
        kicklink_redirect_profile(t('kick_link_failed'), 'is-danger');
    }

    [$tokenStatus, $tokenBody] = kicklink_http_json(
        'POST',
        'https://id.kick.com/oauth/token',
        ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $kickClientId,
            'client_secret' => $kickClientSecret,
            'redirect_uri' => $kickRedirectUri,
            'code_verifier' => $verifier,
            'code' => $code,
        ])
    );
    $access = trim((string)($tokenBody['access_token'] ?? ''));
    $refresh = trim((string)($tokenBody['refresh_token'] ?? ''));
    if ($tokenStatus !== 200 || $access === '' || $refresh === '') {
        error_log('[kicklink] token exchange failed http=' . $tokenStatus . ' error=' . (string)($tokenBody['error'] ?? $tokenBody['message'] ?? ''));
        kicklink_redirect_profile(t('kick_link_failed'), 'is-danger');
    }

    $authHeaders = [
        'Authorization: Bearer ' . $access,
        'Accept: application/json',
    ];
    [$userStatus, $userBody] = kicklink_http_json('GET', 'https://api.kick.com/public/v1/users', $authHeaders);
    $userRow = kicklink_first_item($userBody);
    $kickUserId = trim((string)($userRow['user_id'] ?? $userRow['id'] ?? ''));
    $kickName = strtolower(trim((string)($userRow['name'] ?? $userRow['username'] ?? $userRow['slug'] ?? '')));
    if ($userStatus !== 200 || $kickUserId === '') {
        error_log('[kicklink] users lookup failed http=' . $userStatus);
        kicklink_redirect_profile(t('kick_link_failed'), 'is-danger');
    }

    [$channelStatus, $channelBody] = kicklink_http_json('GET', 'https://api.kick.com/public/v1/channels', $authHeaders);
    $channelRow = kicklink_first_item($channelBody);
    $kickSlug = strtolower(trim((string)($channelRow['slug'] ?? $channelRow['slug_name'] ?? $kickName)));
    if ($kickSlug === '') {
        error_log('[kicklink] channels lookup failed http=' . $channelStatus);
        kicklink_redirect_profile(t('kick_link_failed'), 'is-danger');
    }

    $chatroomId = kicklink_chatroom_id($kickSlug, $kickUserId);
    if ($chatroomId === '') {
        error_log('[kicklink] chatroom id missing for slug=' . $kickSlug);
        kicklink_redirect_profile(t('kick_link_failed'), 'is-danger');
    }

    $saved = kick_bot_save_tokens($conn, $twitchLogin, [
        'kick_username' => $kickSlug,
        'kick_user_id' => $kickUserId,
        'chatroom_id' => $chatroomId,
        'access_token' => $access,
        'refresh_token' => $refresh,
    ]);
    if (!$saved) {
        error_log('[kicklink] save failed: ' . (string)$conn->error);
        kicklink_redirect_profile(t('kick_link_failed'), 'is-danger');
    }
    kicklink_redirect_profile(t('kick_linked_success'), 'is-success');
}

$verifier = kick_bot_pkce_verifier();
$state = bin2hex(random_bytes(16));
$_SESSION['kick_oauth_verifier'] = $verifier;
$_SESSION['kick_oauth_state'] = $state;

$authUrl = 'https://id.kick.com/oauth/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id' => $kickClientId,
    'redirect_uri' => $kickRedirectUri,
    'scope' => $kickScopes,
    'state' => $state,
    'code_challenge' => kick_bot_pkce_challenge($verifier),
    'code_challenge_method' => 'S256',
]);
header('Location: ' . $authUrl);
exit();
