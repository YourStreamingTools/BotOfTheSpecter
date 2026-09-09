<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';
require_once '/var/www/config/db_connect.php';
include 'includes/userdata.php';
include 'includes/mod_access.php';
require_once __DIR__ . '/includes/youtube.php';

$pageTitle = t('youtube_link_page_title');
$isActAsUser = isset($isActAs) && $isActAs === true;
$userId = (int) ($user_id ?? ($_SESSION['user_id'] ?? 0));

if (!youtube_admin_testing()) {
    session_write_close();
    ob_start();
    ?>
<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title">
            <i class="fab fa-youtube"></i>
            <?php echo t('youtube_link_page_title'); ?>
        </div>
        <span class="sp-badge sp-badge-amber"><i class="fas fa-clock"></i> <?php echo t('coming_soon'); ?></span>
    </div>
    <div class="sp-card-body">
        <p><?php echo t('youtube_public_coming_soon'); ?></p>
    </div>
</div>
    <?php
    $content = ob_get_clean();
    include 'layout.php';
    exit();
}

function youtubelink_redirect(string $message, string $alertClass): void
{
    $_SESSION['youtube_message'] = $message;
    $_SESSION['youtube_alert_class'] = $alertClass;
    header('Location: youtubelink.php');
    exit();
}

if ($isActAsUser && (isset($_GET['code']) || isset($_GET['connect']) || (isset($_POST['action']) && $_POST['action'] !== 'save_settings'))) {
    youtubelink_redirect(t('youtube_link_actas_disabled'), 'is-warning');
}

if (!youtube_configured()) {
    $message = t('youtube_app_not_configured');
    $messageType = 'is-danger';
} else {
    $message = '';
    $messageType = '';
}

if (isset($_SESSION['youtube_message'])) {
    $message = (string) $_SESSION['youtube_message'];
    $messageType = (string) ($_SESSION['youtube_alert_class'] ?? 'is-info');
    unset($_SESSION['youtube_message'], $_SESSION['youtube_alert_class']);
}

if (isset($_GET['error'])) {
    youtubelink_redirect(t('youtube_link_denied'), 'is-warning');
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
        youtubelink_redirect(t('youtube_link_no_refresh'), 'is-danger');
    }
    $channelResp = youtube_channels_mine($access);
    $channelJson = $channelResp['json'];
    $reason = youtube_google_reason($channelJson);
    if ($reason === 'youtubeSignupRequired' || $channelResp['code'] === 401 || $channelResp['code'] === 403) {
        if ($reason === 'youtubeSignupRequired' || $reason === 'insufficientPermissions') {
            youtube_revoke($refresh);
            youtubelink_redirect(
                $reason === 'youtubeSignupRequired' ? t('youtube_link_no_channel') : t('youtube_link_missing_scope'),
                'is-warning'
            );
        }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'disconnect') {
        if ($isActAsUser) {
            youtubelink_redirect(t('youtube_link_actas_disabled'), 'is-warning');
        }
        youtube_delete_link($conn, $userId);
        youtubelink_redirect(t('youtube_disconnected_success'), 'is-success');
    }
    if ($action === 'save_settings') {
        $autoUpload = isset($_POST['auto_upload']) ? 1 : 0;
        $privacy = youtube_privacy_allowed($_POST['privacy_status'] ?? 'private');
        if (youtube_update_settings($conn, $userId, $autoUpload, $privacy)) {
            youtubelink_redirect(t('youtube_settings_saved'), 'is-success');
        }
        youtubelink_redirect(t('youtube_settings_save_failed'), 'is-danger');
    }
}

if (isset($_GET['connect']) && youtube_configured()) {
    if ($isActAsUser) {
        youtubelink_redirect(t('youtube_link_actas_disabled'), 'is-warning');
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['youtube_oauth_state'] = $state;
    header('Location: ' . youtube_auth_url($state));
    exit();
}

session_write_close();

$linkRow = youtube_token_row($conn, $userId);
$linked = $linkRow
    && trim((string) ($linkRow['refresh_token'] ?? '')) !== ''
    && (int) ($linkRow['needs_reauth'] ?? 0) === 0;
$needsReauth = $linkRow && (int) ($linkRow['needs_reauth'] ?? 0) === 1;
$canUpload = $linked && (int) ($linkRow['can_upload'] ?? 0) === 1;
$uploads = $linked ? youtube_uploads_for_user($conn, $userId, 25) : [];

ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title">
            <i class="fab fa-youtube"></i>
            <?php echo t('youtube_link_page_title'); ?>
        </div>
        <?php if ($linked): ?>
            <span class="sp-badge sp-badge-green"><i class="fas fa-check-circle"></i> <?php echo t('youtube_badge_connected'); ?></span>
        <?php elseif ($needsReauth): ?>
            <span class="sp-badge sp-badge-amber"><i class="fas fa-exclamation-circle"></i> <?php echo t('youtube_badge_reauth'); ?></span>
        <?php else: ?>
            <span class="sp-badge sp-badge-red"><i class="fas fa-times-circle"></i> <?php echo t('youtube_badge_not_connected'); ?></span>
        <?php endif; ?>
    </div>
    <div class="sp-card-body">
        <p class="sp-help"><?php echo t('youtube_link_intro'); ?></p>
        <?php if ($message): ?>
            <?php
                if ($messageType === 'is-success') $alertClass = 'sp-alert-success';
                elseif ($messageType === 'is-danger') $alertClass = 'sp-alert-danger';
                elseif ($messageType === 'is-warning') $alertClass = 'sp-alert-warning';
                else $alertClass = 'sp-alert-info';
            ?>
            <div class="sp-alert <?php echo $alertClass; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        <div class="sp-alert sp-alert-info">
            <i class="fas fa-info-circle"></i>
            <?php echo t('youtube_privacy_audit_note'); ?>
        </div>
        <?php if ($linked): ?>
            <div class="youtube-channel-row">
                <?php if (!empty($linkRow['channel_thumbnail'])): ?>
                    <img class="youtube-channel-thumb" src="<?php echo htmlspecialchars($linkRow['channel_thumbnail']); ?>" alt="">
                <?php else: ?>
                    <span class="youtube-channel-thumb youtube-channel-thumb-fallback"><i class="fab fa-youtube"></i></span>
                <?php endif; ?>
                <div>
                    <p class="youtube-channel-title"><?php echo htmlspecialchars((string) ($linkRow['channel_title'] ?? '')); ?></p>
                    <?php if (!empty($linkRow['channel_custom_url'])): ?>
                        <p class="sp-help"><?php echo htmlspecialchars((string) $linkRow['channel_custom_url']); ?></p>
                    <?php endif; ?>
                    <p class="sp-help"><?php echo t('youtube_channel_id_label'); ?> <code><?php echo htmlspecialchars((string) ($linkRow['channel_id'] ?? '')); ?></code></p>
                    <?php if (!$canUpload): ?>
                        <p class="sp-help sp-help-warning"><?php echo t('youtube_link_readonly_only'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <form method="post" class="youtube-settings-form">
                <input type="hidden" name="action" value="save_settings">
                <div class="sp-form-group">
                    <label class="sp-label" for="youtube-privacy"><?php echo t('youtube_privacy_label'); ?></label>
                    <select class="sp-select" id="youtube-privacy" name="privacy_status">
                        <?php
                        $currentPrivacy = youtube_privacy_allowed($linkRow['privacy_status'] ?? 'private');
                        $privacyOptions = [
                            'private' => t('youtube_privacy_private'),
                            'unlisted' => t('youtube_privacy_unlisted'),
                            'public' => t('youtube_privacy_public'),
                        ];
                        foreach ($privacyOptions as $value => $label):
                        ?>
                            <option value="<?php echo $value; ?>" <?php echo $currentPrivacy === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="sp-help"><?php echo t('youtube_privacy_help'); ?></span>
                </div>
                <div class="sp-form-group">
                    <label class="youtube-toggle">
                        <input type="checkbox" name="auto_upload" value="1" <?php echo !empty($linkRow['auto_upload']) ? 'checked' : ''; ?>>
                        <?php echo t('youtube_auto_upload_label'); ?>
                    </label>
                    <span class="sp-help"><?php echo t('youtube_auto_upload_help'); ?></span>
                </div>
                <div class="sp-btn-group">
                    <button type="submit" class="sp-btn sp-btn-primary"><?php echo t('youtube_save_settings'); ?></button>
                </div>
            </form>
            <?php if (!$isActAsUser): ?>
            <form method="post" class="youtube-disconnect-form" onsubmit="return confirm(<?php echo json_encode(t('confirm_disconnect_youtube_text')); ?>);">
                <input type="hidden" name="action" value="disconnect">
                <button type="submit" class="sp-btn sp-btn-danger"><?php echo t('disconnect'); ?></button>
            </form>
            <?php endif; ?>
        <?php else: ?>
            <p><?php echo t('youtube_link_prompt'); ?></p>
            <?php if ($isActAsUser): ?>
                <p class="sp-help sp-help-warning"><?php echo t('youtube_link_actas_disabled'); ?></p>
            <?php elseif (youtube_configured()): ?>
                <a class="sp-btn sp-btn-primary" href="youtubelink.php?connect=1">
                    <i class="fab fa-youtube"></i>
                    <?php echo t('youtube_link_button'); ?>
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php if ($linked): ?>
<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-cloud-upload-alt"></i> <?php echo t('youtube_uploads_heading'); ?></div>
    </div>
    <div class="sp-card-body">
        <p class="sp-help"><?php echo t('youtube_uploads_help'); ?></p>
        <?php if (!$uploads): ?>
            <p class="sp-help"><?php echo t('youtube_uploads_empty'); ?></p>
        <?php else: ?>
            <div class="sp-table-wrap">
                <table class="sp-table">
                    <thead>
                        <tr>
                            <th><?php echo t('youtube_th_file'); ?></th>
                            <th><?php echo t('youtube_th_status'); ?></th>
                            <th><?php echo t('youtube_th_video'); ?></th>
                            <th><?php echo t('youtube_th_updated'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($uploads as $job): ?>
                            <?php
                            $status = (string) ($job['status'] ?? '');
                            $badge = 'sp-badge-grey';
                            if ($status === 'done') $badge = 'sp-badge-green';
                            elseif ($status === 'failed') $badge = 'sp-badge-red';
                            elseif ($status === 'uploading') $badge = 'sp-badge-blue';
                            elseif ($status === 'queued') $badge = 'sp-badge-amber';
                            $videoId = trim((string) ($job['youtube_video_id'] ?? ''));
                            ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars((string) ($job['filename'] ?? '')); ?></code></td>
                                <td>
                                    <span class="sp-badge <?php echo $badge; ?>"><?php echo htmlspecialchars($status); ?></span>
                                    <?php if (!empty($job['error_message'])): ?>
                                        <div class="sp-help"><?php echo htmlspecialchars((string) $job['error_message']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($videoId !== ''): ?>
                                        <a href="https://youtu.be/<?php echo rawurlencode($videoId); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($videoId); ?></a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars((string) ($job['updated_at'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
include 'layout.php';
?>
