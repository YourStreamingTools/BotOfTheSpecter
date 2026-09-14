<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';
require_once '/var/www/config/db_connect.php';
include '/var/www/config/twitch.php';
include 'includes/userdata.php';
include 'includes/mod_access.php';
require_once __DIR__ . '/includes/youtube.php';
if (function_exists('botofthespecter_twitch_apply_db_override')) {
    botofthespecter_twitch_apply_db_override($conn, $clientID, $clientSecret, $oauth);
}

$isActAsUser = isset($isActAs) && $isActAs === true;
$userId = (int) ($user_id ?? ($_SESSION['user_id'] ?? 0));

function youtubelink_redirect(string $message, string $alertClass): void
{
    $_SESSION['youtube_message'] = $message;
    $_SESSION['youtube_alert_class'] = $alertClass;
    header('Location: streaming.php#youtube');
    exit();
}

if ($isActAsUser && (isset($_GET['code']) || isset($_GET['connect']))) {
    youtubelink_redirect(t('youtube_link_actas_disabled'), 'is-warning');
}

if (isset($_GET['error'])) {
    youtubelink_redirect(t('youtube_link_denied'), 'is-warning');
}

if (isset($_GET['connect']) && youtube_configured()) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['youtube_oauth_state'] = $state;
    header('Location: ' . youtube_auth_url($state));
    exit();
}

if (isset($_GET['code']) && youtube_configured()) {
    $code = trim((string) $_GET['code']);
    $state = (string) ($_GET['state'] ?? '');
    $expectedState = (string) ($_SESSION['youtube_oauth_state'] ?? '');
    unset($_SESSION['youtube_oauth_state']);
    if ($code === '' || $state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
        youtubelink_redirect(t('youtube_link_failed'), 'is-danger');
    }
    $tokenResp = youtube_exchange_code($code);
    $tokens = $tokenResp['json'];
    $access = trim((string) ($tokens['access_token'] ?? ''));
    $refresh = trim((string) ($tokens['refresh_token'] ?? ''));
    if ($tokenResp['code'] !== 200 || $access === '') {
        error_log('[youtubelink] token exchange failed http=' . $tokenResp['code'] . ' error=' . youtube_google_reason($tokens));
        youtubelink_redirect(t('youtube_link_failed'), 'is-danger');
    }
    if ($refresh === '') {
        youtubelink_redirect(t('youtube_link_no_refresh'), 'is-warning');
    }
    $channelResp = youtube_channels_mine($access);
    $channelJson = $channelResp['json'];
    $reason = youtube_google_reason($channelJson);
    if ($reason === 'youtubeSignupRequired' || $reason === 'insufficientPermissions') {
        youtube_revoke($refresh);
        youtubelink_redirect(
            $reason === 'youtubeSignupRequired' ? t('youtube_link_no_channel') : t('youtube_link_missing_scope'),
            'is-warning'
        );
    }
    $items = $channelJson['items'] ?? [];
    if ($channelResp['code'] !== 200 || !is_array($items) || $items === []) {
        youtube_revoke($refresh);
        youtubelink_redirect(t('youtube_link_no_channel'), 'is-warning');
    }
    $saved = youtube_save_link($conn, $userId, $tokens, $items[0]);
    if (!$saved) {
        error_log('[youtubelink] save failed: ' . (string) $conn->error);
        youtubelink_redirect(t('youtube_link_failed'), 'is-danger');
    }
    if (!youtube_has_upload_scope((string) ($tokens['scope'] ?? ''))) {
        youtubelink_redirect(t('youtube_link_readonly_only'), 'is-warning');
    }
    youtubelink_redirect(t('youtube_linked_success'), 'is-success');
}

header('Location: streaming.php#youtube');
exit();
