<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';
require_once '/var/www/config/db_connect.php';
require_once '/var/www/config/stream.php';
include '/var/www/config/twitch.php';
include 'includes/userdata.php';
include 'includes/mod_access.php';
require_once __DIR__ . '/includes/youtube.php';
require_once __DIR__ . '/includes/stream_api_client.php';
if (function_exists('botofthespecter_twitch_apply_db_override')) {
    botofthespecter_twitch_apply_db_override($conn, $clientID, $clientSecret, $oauth);
}

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

if ($isActAsUser && (isset($_GET['code']) || isset($_GET['connect']) || isset($_POST['action']))) {
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
    if ($action === 'store_twitch_vod') {
        if ($isActAsUser) {
            youtubelink_redirect(t('youtube_link_actas_disabled'), 'is-warning');
        }
        $vodId = trim((string) ($_POST['vod_id'] ?? ''));
        $vodTitle = trim((string) ($_POST['vod_title'] ?? ''));
        $apiBase = rtrim((string) ($stream_api_base ?? ''), '/');
        $apiKey = (string) ($api_key ?? ($_SESSION['api_key'] ?? ''));
        $pull = streamApiRequest(
            $apiBase,
            $apiKey,
            '/api/me/recordings/pull-twitch',
            30,
            'POST',
            ['vod_id' => $vodId, 'title' => $vodTitle]
        );
        if ($pull['ok'] || (int) ($pull['http'] ?? 0) === 202) {
            $_SESSION['youtube_vod_message'] = t('youtube_vod_store_started');
            $_SESSION['youtube_vod_alert_class'] = 'is-success';
            header('Location: youtubelink.php#stored-vods');
            exit();
        }
        if ((int) ($pull['http'] ?? 0) === 507) {
            $_SESSION['youtube_vod_message'] = t('youtube_vod_store_full');
            $_SESSION['youtube_vod_alert_class'] = 'is-warning';
            header('Location: youtubelink.php#stored-vods');
            exit();
        }
        $_SESSION['youtube_vod_message'] = t('youtube_vod_store_failed');
        $_SESSION['youtube_vod_alert_class'] = 'is-danger';
        header('Location: youtubelink.php#stored-vods');
        exit();
    }
    if ($action === 'send_twitch_youtube') {
        if ($isActAsUser) {
            youtubelink_redirect(t('youtube_link_actas_disabled'), 'is-warning');
        }
        $vodId = trim((string) ($_POST['vod_id'] ?? ''));
        $vodTitle = trim((string) ($_POST['vod_title'] ?? ''));
        $queued = youtube_enqueue_twitch_vod($conn, $userId, $vodId, $vodTitle !== '' ? $vodTitle : null);
        if (!empty($queued['ok'])) {
            youtubelink_redirect(t('youtube_vod_youtube_queued'), 'is-success');
        }
        youtubelink_redirect(t('youtube_vod_youtube_failed'), 'is-danger');
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

$vodMessage = '';
$vodMessageType = '';
if (isset($_SESSION['youtube_vod_message'])) {
    $vodMessage = (string) $_SESSION['youtube_vod_message'];
    $vodMessageType = (string) ($_SESSION['youtube_vod_alert_class'] ?? 'is-info');
    unset($_SESSION['youtube_vod_message'], $_SESSION['youtube_vod_alert_class']);
}

session_write_close();

$linkRow = youtube_token_row($conn, $userId);
$linked = $linkRow
    && trim((string) ($linkRow['refresh_token'] ?? '')) !== ''
    && (int) ($linkRow['needs_reauth'] ?? 0) === 0;
$needsReauth = $linkRow && (int) ($linkRow['needs_reauth'] ?? 0) === 1;
$canUpload = $linked && (int) ($linkRow['can_upload'] ?? 0) === 1;

$streamApiBase = rtrim((string) ($stream_api_base ?? ''), '/');
$streamApiTimeout = (int) ($stream_api_timeout ?? 30);
$streamUserApiKey = (string) ($api_key ?? ($_SESSION['api_key'] ?? ''));
$storageSummary = streamFetchStorage($streamApiBase, $streamUserApiKey, $streamApiTimeout);
$storageUsedBytes = $storageSummary['used_bytes'];
$storageQuotaBytes = $storageSummary['quota_bytes'];
$storageUnlimited = $storageSummary['unlimited'];
$storedFiles = [];
$pullJobs = [];
$list = streamApiRequest($streamApiBase, $streamUserApiKey, '/api/me/recordings', $streamApiTimeout);
if ($list['ok']) {
    $payload = json_decode((string) $list['body'], true);
    if (is_array($payload) && !empty($payload['files']) && is_array($payload['files'])) {
        $storedFiles = $payload['files'];
    }
    if (is_array($payload) && !empty($payload['pulls']) && is_array($payload['pulls'])) {
        $pullJobs = $payload['pulls'];
    }
}
$storedByTwitchId = [];
foreach ($storedFiles as $stored) {
    $name = (string) ($stored['name'] ?? '');
    $tid = (string) ($stored['twitch_video_id'] ?? '');
    if ($tid === '' && preg_match('/^twitch-([0-9]{1,20})\.mp4(?:\.part)?$/i', $name, $m)) {
        $tid = $m[1];
    }
    if ($tid !== '') {
        $storedByTwitchId[$tid] = $stored;
    }
}
foreach ($pullJobs as $job) {
    $tid = (string) ($job['vod_id'] ?? '');
    if ($tid !== '' && !isset($storedByTwitchId[$tid])) {
        $storedByTwitchId[$tid] = [
            'name' => $job['filename'] ?? '',
            'is_partial' => ($job['status'] ?? '') === 'pulling',
            'twitch_video_id' => $tid,
            'pull' => $job,
        ];
    }
}

if (isset($_GET['ajax']) && (string) $_GET['ajax'] === 'vods') {
    header('Content-Type: application/json');
    echo json_encode([
        'files' => $storedFiles,
        'pulls' => $pullJobs,
        'storage' => [
            'used_bytes' => $storageUsedBytes,
            'quota_bytes' => $storageQuotaBytes,
            'unlimited' => $storageUnlimited,
        ],
    ]);
    exit();
}

$twitchVideos = [];
$twitchVideosError = '';
$accessToken = (string) ($_SESSION['access_token'] ?? '');
$channelUserId = trim((string) ($_SESSION['twitchUserId'] ?? ''));
if ($accessToken !== '' && $channelUserId !== '' && !empty($clientID)) {
    $helixUrl = 'https://api.twitch.tv/helix/videos?' . http_build_query([
        'user_id' => $channelUserId,
        'first' => 20,
    ]);
    $ch = curl_init($helixUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Client-Id: ' . $clientID,
    ]);
    $helixBody = curl_exec($ch);
    $helixCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $helixJson = json_decode((string) $helixBody, true);
    if ($helixCode !== 200) {
        $twitchVideosError = t('youtube_vod_helix_failed');
    } else {
        $twitchVideos = is_array($helixJson['data'] ?? null) ? $helixJson['data'] : [];
    }
}

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
<?php include __DIR__ . '/includes/stream_storage_bar.php'; ?>
<div class="sp-card" id="stored-vods">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-folder-open"></i> <?php echo t('youtube_vod_stored_heading'); ?></div>
    </div>
    <div class="sp-card-body">
        <?php if ($vodMessage): ?>
            <?php
                if ($vodMessageType === 'is-success') $vodAlert = 'sp-alert-success';
                elseif ($vodMessageType === 'is-danger') $vodAlert = 'sp-alert-danger';
                elseif ($vodMessageType === 'is-warning') $vodAlert = 'sp-alert-warning';
                else $vodAlert = 'sp-alert-info';
            ?>
            <div class="sp-alert <?php echo $vodAlert; ?>"><?php echo htmlspecialchars($vodMessage); ?></div>
        <?php endif; ?>
        <p class="sp-help"><?php echo t('youtube_vod_stored_help'); ?></p>
        <div id="youtube-vod-status">
            <?php
            $activePulls = array_values(array_filter($pullJobs, static function ($job) {
                return is_array($job) && ($job['status'] ?? '') === 'pulling';
            }));
            $failedPulls = array_values(array_filter($pullJobs, static function ($job) {
                return is_array($job) && ($job['status'] ?? '') === 'failed';
            }));
            $storedReady = array_values(array_filter($storedFiles, static function ($file) {
                $name = (string) ($file['name'] ?? '');
                return empty($file['is_partial']) && (bool) preg_match('/^twitch-[0-9].*\.mp4$/i', $name);
            }));
            ?>
            <?php if (!$activePulls && !$failedPulls && !$storedReady): ?>
                <p class="sp-help"><?php echo t('youtube_vod_stored_empty'); ?></p>
            <?php endif; ?>
            <?php foreach ($activePulls as $job): ?>
                <?php
                $pct = $job['percent'];
                $pctVal = is_numeric($pct) ? max(0, min(100, (float) $pct)) : 0;
                $label = (string) ($job['title'] ?: ($job['filename'] ?? $job['vod_id'] ?? ''));
                ?>
                <div class="media-storage-bar mb-4">
                    <div class="media-storage-header">
                        <span><?php echo htmlspecialchars($label); ?></span>
                        <span><?php echo is_numeric($pct) ? htmlspecialchars((string) $pctVal) . '%' : t('youtube_vod_status_pulling'); ?></span>
                    </div>
                    <progress class="progress" value="<?php echo htmlspecialchars((string) $pctVal); ?>" max="100"></progress>
                </div>
            <?php endforeach; ?>
            <?php foreach ($failedPulls as $job): ?>
                <div class="sp-alert sp-alert-danger mb-4">
                    <?php echo htmlspecialchars((string) ($job['title'] ?: ($job['filename'] ?? ''))); ?>
                    — <?php echo t('youtube_vod_status_failed'); ?>
                </div>
            <?php endforeach; ?>
            <?php if ($storedReady): ?>
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th><?php echo t('youtube_vod_th_title'); ?></th>
                                <th><?php echo t('recording_th_size'); ?></th>
                                <th><?php echo t('youtube_vod_th_action'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($storedReady as $file): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(preg_replace('/\.mp4$/i', '', (string) ($file['name'] ?? ''))); ?></td>
                                    <td><?php echo !empty($file['size_bytes']) ? htmlspecialchars((string) round(((int) $file['size_bytes']) / 1048576, 1)) . ' MB' : '—'; ?></td>
                                    <td>
                                        <?php if (!empty($file['download_url'])): ?>
                                            <a class="sp-btn sp-btn-secondary sp-btn-sm" href="<?php echo htmlspecialchars((string) $file['download_url']); ?>"><?php echo t('recording_btn_download'); ?></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-cloud-download-alt"></i> <?php echo t('youtube_vod_fetch_heading'); ?></div>
    </div>
    <div class="sp-card-body">
        <p class="sp-help"><?php echo t('youtube_vod_fetch_help'); ?></p>
        <?php if ($twitchVideosError): ?>
            <div class="sp-alert sp-alert-warning"><?php echo htmlspecialchars($twitchVideosError); ?></div>
        <?php elseif (!$twitchVideos): ?>
            <p class="sp-help"><?php echo t('youtube_vod_fetch_empty'); ?></p>
        <?php else: ?>
            <div class="sp-table-wrap">
                <table class="sp-table">
                    <thead>
                        <tr>
                            <th><?php echo t('youtube_vod_th_title'); ?></th>
                            <th><?php echo t('youtube_vod_th_type'); ?></th>
                            <th><?php echo t('youtube_vod_th_duration'); ?></th>
                            <th><?php echo t('youtube_vod_th_action'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($twitchVideos as $video): ?>
                            <?php
                            $vid = (string) ($video['id'] ?? '');
                            $vtitle = (string) ($video['title'] ?? '');
                            $vtype = (string) ($video['type'] ?? '');
                            $vdur = (string) ($video['duration'] ?? '');
                            $stored = $storedByTwitchId[$vid] ?? null;
                            $pulling = false;
                            foreach ($pullJobs as $job) {
                                if ((string) ($job['vod_id'] ?? '') === $vid && ($job['status'] ?? '') === 'pulling') {
                                    $pulling = true;
                                    break;
                                }
                            }
                            $ready = $stored && empty($stored['is_partial']) && !$pulling;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($vtitle); ?></td>
                                <td><?php echo htmlspecialchars($vtype); ?></td>
                                <td><?php echo htmlspecialchars($vdur); ?></td>
                                <td>
                                    <?php if ($pulling): ?>
                                        <span class="sp-badge sp-badge-amber"><?php echo t('youtube_vod_status_pulling'); ?></span>
                                    <?php elseif ($ready): ?>
                                        <span class="sp-badge sp-badge-green"><?php echo t('youtube_vod_status_stored'); ?></span>
                                        <?php if (!empty($stored['download_url'])): ?>
                                            <a class="sp-btn sp-btn-secondary sp-btn-sm" href="<?php echo htmlspecialchars((string) $stored['download_url']); ?>"><?php echo t('recording_btn_download'); ?></a>
                                        <?php endif; ?>
                                    <?php elseif (!$isActAsUser): ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="store_twitch_vod">
                                            <input type="hidden" name="vod_id" value="<?php echo htmlspecialchars($vid); ?>">
                                            <input type="hidden" name="vod_title" value="<?php echo htmlspecialchars($vtitle); ?>">
                                            <button type="submit" class="sp-btn sp-btn-primary sp-btn-sm"><?php echo t('youtube_vod_store_btn'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canUpload && !$isActAsUser && $vid !== ''): ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="send_twitch_youtube">
                                            <input type="hidden" name="vod_id" value="<?php echo htmlspecialchars($vid); ?>">
                                            <input type="hidden" name="vod_title" value="<?php echo htmlspecialchars($vtitle); ?>">
                                            <button type="submit" class="sp-btn sp-btn-secondary sp-btn-sm"><?php echo t('videos_send_to_youtube'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    var host = document.getElementById('youtube-vod-status');
    if (!host) return;
    function render(data) {
        if (!data) return;
        var pulls = Array.isArray(data.pulls) ? data.pulls : [];
        var files = Array.isArray(data.files) ? data.files : [];
        var html = '';
        var active = pulls.filter(function (j) { return j && j.status === 'pulling'; });
        var failed = pulls.filter(function (j) { return j && j.status === 'failed'; });
        var stored = files.filter(function (f) {
            return f && !f.is_partial && /^twitch-[0-9].*\.mp4$/i.test(String(f.name || ''));
        });
        if (!active.length && !failed.length && !stored.length) {
            html = '<p class="sp-help"><?php echo htmlspecialchars(t('youtube_vod_stored_empty')); ?></p>';
        }
        active.forEach(function (job) {
            var pct = (typeof job.percent === 'number') ? Math.max(0, Math.min(100, job.percent)) : 0;
            var label = job.title || job.filename || job.vod_id || '';
            var pctLabel = (typeof job.percent === 'number') ? (pct.toFixed(1) + '%') : <?php echo json_encode(t('youtube_vod_status_pulling')); ?>;
            html += '<div class="media-storage-bar mb-4"><div class="media-storage-header"><span>' +
                label.replace(/[<>&]/g, '') + '</span><span>' + pctLabel + '</span></div>' +
                '<progress class="progress" value="' + pct + '" max="100"></progress></div>';
        });
        failed.forEach(function (job) {
            html += '<div class="sp-alert sp-alert-danger mb-4">' +
                String(job.title || job.filename || '').replace(/[<>&]/g, '') +
                ' — ' + <?php echo json_encode(t('youtube_vod_status_failed')); ?> + '</div>';
        });
        if (stored.length) {
            html += '<div class="sp-table-wrap"><table class="sp-table"><thead><tr><th><?php echo htmlspecialchars(t('youtube_vod_th_title')); ?></th><th><?php echo htmlspecialchars(t('recording_th_size')); ?></th><th><?php echo htmlspecialchars(t('youtube_vod_th_action')); ?></th></tr></thead><tbody>';
            stored.forEach(function (file) {
                var name = String(file.name || '').replace(/\.mp4$/i, '');
                var mb = file.size_bytes ? ((file.size_bytes / 1048576).toFixed(1) + ' MB') : '—';
                var dl = file.download_url
                    ? '<a class="sp-btn sp-btn-secondary sp-btn-sm" href="' + String(file.download_url).replace(/"/g, '') + '"><?php echo htmlspecialchars(t('recording_btn_download')); ?></a>'
                    : '';
                html += '<tr><td>' + name.replace(/[<>&]/g, '') + '</td><td>' + mb + '</td><td>' + dl + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }
        host.innerHTML = html;
        if (window.updateStorageBar && data.storage) {
            window.updateStorageBar(data.storage);
        }
    }
    function poll() {
        var url = 'youtubelink.php?ajax=vods&_ts=' + Date.now();
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function () {});
    }
    setInterval(poll, 3000);
})();
</script>
<?php
$content = ob_get_clean();
include 'layout.php';
?>
