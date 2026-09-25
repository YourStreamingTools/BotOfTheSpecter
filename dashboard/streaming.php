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
include 'includes/user_db_connect.php';
require_once __DIR__ . '/includes/youtube.php';
require_once __DIR__ . '/includes/user_s3.php';
require_once __DIR__ . '/includes/stream_api_client.php';
if (function_exists('botofthespecter_twitch_apply_db_override')) {
    botofthespecter_twitch_apply_db_override($conn, $clientID, $clientSecret, $oauth);
}

$pageTitle = t('menu_streaming');

$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

require_once __DIR__ . '/includes/stream_hub_data.php';
session_write_close();

$storedByTwitchId = [];
foreach ($libraryFiles as $stored) {
    $tid = (string) ($stored['twitch_video_id'] ?? '');
    if ($tid === '' && preg_match('/^twitch-([0-9]{1,20})\.mp4(?:\.part)?$/i', (string) ($stored['name'] ?? ''), $m)) {
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
        ];
    }
}
$activePulls = array_values(array_filter($pullJobs, static function ($job) {
    return is_array($job) && ($job['status'] ?? '') === 'pulling';
}));
$failedPulls = array_values(array_filter($pullJobs, static function ($job) {
    return is_array($job) && ($job['status'] ?? '') === 'failed';
}));

$ytProgressHtml = '';
foreach ($youtubeJobs as $jobName => $job) {
    if (!is_array($job)) {
        continue;
    }
    $ytBarStatus = (string) ($job['status'] ?? '');
    if ($ytBarStatus !== 'uploading' && $ytBarStatus !== 'pulling') {
        continue;
    }
    if (function_exists('youtube_job_is_live') && !youtube_job_is_live($job)) {
        continue;
    }
    $ytClient = youtube_job_client_row($job);
    $pctVal = max(0, min(100, (float) $ytClient['percent']));
    $label = (string) ($ytClient['title'] ?: $jobName);
    $phase = $ytBarStatus === 'pulling' ? t('youtube_status_pulling') : t('youtube_status_uploading');
    $right = rtrim(rtrim(number_format($pctVal, 1, '.', ''), '0'), '.') . '%';
    if ($ytBarStatus === 'uploading' && (int) $ytClient['bytes_total'] > 0) {
        $right .= ' · ' . formatBytes((int) $ytClient['bytes_sent']) . ' / ' . formatBytes((int) $ytClient['bytes_total']);
    } elseif ($ytBarStatus === 'pulling' && (int) $ytClient['bytes_sent'] > 0) {
        $right .= ' · ' . formatBytes((int) $ytClient['bytes_sent']);
    }
    $ytProgressHtml .= '<div class="media-storage-bar mb-4 stream-hub-upload-bar"><div class="media-storage-header"><span>'
        . htmlspecialchars($label) . ' — ' . htmlspecialchars($phase)
        . '</span><span>' . htmlspecialchars($right) . '</span></div>'
        . '<progress class="progress" value="' . htmlspecialchars((string) $pctVal) . '" max="100"></progress></div>';
}

$s3Jobs = isset($s3Jobs) && is_array($s3Jobs) ? $s3Jobs : [];
$s3ProgressHtml = '';
foreach ($s3Jobs as $jobName => $job) {
    if (!is_array($job)) {
        continue;
    }
    $s3BarStatus = (string) ($job['status'] ?? '');
    if ($s3BarStatus !== 'uploading' && $s3BarStatus !== 'queued') {
        continue;
    }
    if ($s3BarStatus === 'uploading' && function_exists('user_s3_job_is_live') && !user_s3_job_is_live($job)) {
        continue;
    }
    $s3Client = user_s3_job_client_row($job);
    $pctVal = max(0, min(100, (float) $s3Client['percent']));
    $label = (string) ($s3Client['title'] ?: $jobName);
    $phase = $s3BarStatus === 'queued' ? t('s3_vod_status_queued') : t('s3_vod_status_uploading');
    $right = $s3BarStatus === 'queued' ? '' : (rtrim(rtrim(number_format($pctVal, 1, '.', ''), '0'), '.') . '%');
    if ($s3BarStatus === 'uploading' && (int) $s3Client['bytes_total'] > 0) {
        $right .= ($right !== '' ? ' · ' : '') . formatBytes((int) $s3Client['bytes_sent']) . ' / ' . formatBytes((int) $s3Client['bytes_total']);
    }
    $s3ProgressHtml .= '<div class="media-storage-bar mb-4 stream-hub-upload-bar"><div class="media-storage-header"><span>'
        . htmlspecialchars($label) . ' — ' . htmlspecialchars($phase)
        . '</span><span>' . htmlspecialchars($right) . '</span></div>';
    if ($s3BarStatus === 'uploading') {
        $s3ProgressHtml .= '<progress class="progress" value="' . htmlspecialchars((string) $pctVal) . '" max="100"></progress>';
    }
    $s3ProgressHtml .= '</div>';
}
$s3Public = function_exists('user_s3_public_row') ? user_s3_public_row($s3Settings ?? null) : ['connected' => false];

ob_start();
$libraryCount = count($libraryFiles);
$ytPullingCount = 0;
foreach ($youtubeJobs as $job) {
    if (is_array($job) && ($job['status'] ?? '') === 'pulling') {
        $ytPullingCount++;
    }
}
$pullingCount = count($activePulls) + $ytPullingCount;
?>
<div class="sp-page-header">
    <h1><?php echo t('menu_streaming'); ?></h1>
    <p><?php echo t('stream_hub_page_lead'); ?></p>
</div>
<?php if ($hubMessage): ?>
    <?php
        if ($hubMessageType === 'is-success') $hubAlert = 'sp-alert-success';
        elseif ($hubMessageType === 'is-danger') $hubAlert = 'sp-alert-danger';
        elseif ($hubMessageType === 'is-warning') $hubAlert = 'sp-alert-warning';
        else $hubAlert = 'sp-alert-info';
    ?>
    <div class="sp-alert <?php echo $hubAlert; ?> mb-4"><?php echo htmlspecialchars($hubMessage); ?></div>
<?php endif; ?>
<div class="sp-stat-row">
    <div class="sp-stat">
        <span class="sp-stat-label"><?php echo t('stream_hub_stat_files'); ?></span>
        <span class="sp-stat-value" id="stream-hub-stat-files"><?php echo (int) $libraryCount; ?></span>
    </div>
    <div class="sp-stat<?php echo $pullingCount > 0 ? ' warn' : ''; ?>">
        <span class="sp-stat-label"><?php echo t('stream_hub_stat_pulling'); ?></span>
        <span class="sp-stat-value" id="stream-hub-stat-pulling"><?php echo (int) $pullingCount; ?></span>
    </div>
    <div class="sp-stat<?php echo $storageUnlimited ? ' online' : ''; ?>">
        <span class="sp-stat-label"><?php echo t('recording_storage_usage'); ?></span>
        <span class="sp-stat-value" id="stream-hub-stat-used"><?php echo htmlspecialchars(formatBytes((int) $storageUsedBytes)); ?></span>
        <span class="sp-stat-sub"><?php echo $storageUnlimited ? t('streaming_slot_unlimited') : htmlspecialchars(formatBytes((int) $storageQuotaBytes)); ?></span>
    </div>
</div>
<?php include __DIR__ . '/includes/stream_storage_bar.php'; ?>
<ul class="sp-tabs-nav" id="stream-hub-tabs">
    <li class="is-active" data-stream-tab="library"><a href="#library"><i class="fas fa-folder-open"></i> <?php echo t('stream_hub_nav_library'); ?></a></li>
    <?php if ($canYoutube): ?>
    <li data-stream-tab="import"><a href="#import"><i class="fab fa-twitch"></i> <?php echo t('stream_hub_nav_import'); ?></a></li>
    <?php endif; ?>
    <li data-stream-tab="setup"><a href="#setup"><i class="fas fa-cog"></i> <?php echo t('stream_hub_nav_setup'); ?></a></li>
</ul>

<div class="stream-hub-panel is-active" id="panel-library" data-stream-panel="library">
<div class="sp-card" id="library">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-folder-open"></i> <?php echo t('stream_hub_library_heading'); ?></div>
        <div class="youtube-vod-toolbar">
            <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" id="youtube-vod-copy-links" disabled><?php echo t('youtube_vod_copy_links'); ?></button>
            <button type="button" id="refresh-remote-files-btn" class="sp-btn sp-btn-secondary sp-btn-sm">
                <span class="icon"><i class="fas fa-sync-alt"></i></span>
                <span><?php echo t('recording_btn_refresh'); ?></span>
            </button>
        </div>
    </div>
    <div class="sp-card-body">
        <div id="youtube-vod-notice" data-vod-notice></div>
        <p class="sp-help"><?php echo t('stream_hub_library_help'); ?></p>
        <div id="stream-hub-uploads" class="stream-hub-yt-jobs"><?php echo $ytProgressHtml; ?></div>
        <div id="stream-hub-s3-jobs" class="stream-hub-s3-jobs"><?php echo $s3ProgressHtml; ?></div>
        <div id="stream-hub-pulls">
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
        </div>
        <div id="remote-files-container">
            <?php if ($remoteFileError): ?>
                <div class="sp-alert sp-alert-warning"><?php echo htmlspecialchars($remoteFileError); ?></div>
            <?php elseif (!$libraryFiles): ?>
                <div class="stream-hub-empty"><i class="fas fa-folder-open"></i><?php echo t('recording_error_no_files'); ?></div>
            <?php else: ?>
                <div class="sp-table-wrap">
                    <table class="sp-table" id="stream-hub-files">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="youtube-vod-check" id="youtube-vod-select-all" title="<?php echo htmlspecialchars(t('youtube_vod_select_all')); ?>"></th>
                                <th><?php echo t('recording_th_file'); ?></th>
                                <th><?php echo t('recording_th_type'); ?></th>
                                <th><?php echo t('recording_th_size'); ?></th>
                                <th><?php echo t('recording_th_expires'); ?></th>
                                <th><?php echo t('recording_th_action'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($libraryFiles as $file): ?>
                                <?php
                                $displayTitle = recordingDisplayName($file['name'], $file['title'] ?? '');
                                $namedUrl = (string) ($file['download_url'] ?? '');
                                $kind = recordingFileKind($file);
                                $inProgress = in_array($kind, ['recording', 'storing'], true);
                                $canDl = !$inProgress && empty($file['is_partial']) && strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION)) === 'mp4';
                                $tid = recordingTwitchId($file);
                                $ytJob = $youtubeJobs[$file['name']] ?? null;
                                $ytStatus = is_array($ytJob) ? (string) ($ytJob['status'] ?? '') : '';
                                $durSeconds = youtube_parse_duration_seconds($helixDurations[$tid] ?? '');
                                $fileSize = (int) ($file['size'] ?? 0);
                                $ytLimit = youtube_upload_limit_reason($durSeconds, $fileSize > 0 ? $fileSize : null);
                                $showYoutube = $canUpload && !$isActAsUser && $canDl;
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($canDl && $namedUrl !== ''): ?>
                                            <input type="checkbox" class="youtube-vod-check youtube-vod-pick" data-vod-url="<?php echo htmlspecialchars($namedUrl); ?>" data-vod-title="<?php echo htmlspecialchars($displayTitle); ?>" data-vod-name="<?php echo htmlspecialchars($file['name']); ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($displayTitle); ?></td>
                                    <td>
                                        <div class="stream-hub-type">
                                        <?php if ($kind === 'recording'): ?>
                                            <span class="sp-badge sp-badge-amber"><?php echo t('recording_type_in_progress'); ?></span>
                                        <?php elseif ($kind === 'storing'): ?>
                                            <span class="sp-badge sp-badge-amber"><?php echo t('youtube_vod_status_pulling'); ?></span>
                                        <?php elseif ($kind === 'stored'): ?>
                                            <span class="sp-badge sp-badge-blue"><?php echo t('recording_type_stored'); ?></span>
                                        <?php else: ?>
                                            <span class="sp-badge sp-badge-accent"><?php echo t('recording_type_recorded'); ?></span>
                                        <?php endif; ?>
                                        <?php if (($file['storage'] ?? '') === 's4' && $kind !== 'recording' && $kind !== 'storing'): ?>
                                            <span class="sp-badge sp-badge-grey"><?php echo t('recording_type_extended'); ?></span>
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars(formatBytes((int) $file['size'])); ?></td>
                                    <td>
                                        <?php if (!empty($file['expires_unix'])): ?>
                                            <span class="recording-countdown" data-expires="<?php echo (int) $file['expires_unix']; ?>">—</span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($canDl && $namedUrl !== ''): ?>
                                            <div class="stream-hub-file-actions">
                                            <a class="sp-btn sp-btn-primary sp-btn-sm" href="<?php echo htmlspecialchars($namedUrl); ?>"><?php echo t('recording_btn_download'); ?></a>
                                            <?php if (!empty($file['can_extend'])): ?>
                                                <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" data-extend-file="<?php echo htmlspecialchars($file['name']); ?>"><?php echo t('recording_btn_extend'); ?></button>
                                            <?php endif; ?>
                                            <button type="button" class="sp-btn sp-btn-danger sp-btn-sm" data-delete-file="<?php echo htmlspecialchars($file['name']); ?>" data-delete-title="<?php echo htmlspecialchars($displayTitle); ?>"><?php echo t('recording_btn_delete'); ?></button>
                                            <?php if ($showYoutube): ?>
                                                <?php if ($ytStatus === 'done'): ?>
                                                    <span class="sp-badge sp-badge-green"><?php echo t('youtube_status_done'); ?></span>
                                                <?php elseif ($ytStatus === 'queued' || (in_array($ytStatus, ['uploading', 'pulling'], true) && (!function_exists('youtube_job_is_live') || youtube_job_is_live(is_array($ytJob) ? $ytJob : [])))): ?>
                                                    <?php
                                                    $ytClient = youtube_job_client_row(is_array($ytJob) ? $ytJob : []);
                                                    $ytPct = max(0, min(100, (float) $ytClient['percent']));
                                                    $ytPctLabel = '';
                                                    if ($ytStatus !== 'queued' && $ytPct > 0) {
                                                        $ytPctLabel = ' ' . rtrim(rtrim(number_format($ytPct, 1, '.', ''), '0'), '.') . '%';
                                                    }
                                                    ?>
                                                    <span class="sp-badge sp-badge-amber"><?php echo t('youtube_status_' . $ytStatus); ?><?php echo htmlspecialchars($ytPctLabel); ?></span>
                                                <?php elseif ($ytLimit !== null): ?>
                                                    <span class="youtube-upload-limit" title="<?php echo htmlspecialchars(t(youtube_upload_limit_lang_key($ytLimit))); ?>">
                                                        <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" disabled><?php echo t('videos_send_to_youtube'); ?></button>
                                                    </span>
                                                <?php else: ?>
                                                    <form method="post" action="streaming.php#library" data-send-youtube="1">
                                                        <input type="hidden" name="action" value="send_library_youtube">
                                                        <input type="hidden" name="filename" value="<?php echo htmlspecialchars($file['name']); ?>">
                                                        <input type="hidden" name="file_title" value="<?php echo htmlspecialchars($displayTitle); ?>">
                                                        <input type="hidden" name="twitch_video_id" value="<?php echo htmlspecialchars($tid); ?>">
                                                        <input type="hidden" name="file_size" value="<?php echo (int) $file['size']; ?>">
                                                        <input type="hidden" name="file_duration" value="<?php echo htmlspecialchars($helixDurations[$tid] ?? ''); ?>">
                                                        <button type="submit" class="sp-btn sp-btn-secondary sp-btn-sm"><?php echo $ytStatus === 'failed' ? t('youtube_btn_retry') : t('videos_send_to_youtube'); ?></button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php
                                            $s3Job = $s3Jobs[$file['name']] ?? null;
                                            $s3Status = is_array($s3Job) ? (string) ($s3Job['status'] ?? '') : '';
                                            $s3Live = is_array($s3Job) && (!function_exists('user_s3_job_is_live') || user_s3_job_is_live($s3Job) || $s3Status === 'queued');
                                            if ($canS3 && $canDl):
                                                if ($s3Status === 'done'): ?>
                                                    <span class="sp-badge sp-badge-green"><?php echo t('s3_vod_status_done'); ?></span>
                                                <?php elseif ($s3Status === 'queued' || ($s3Status === 'uploading' && $s3Live)): ?>
                                                    <?php
                                                    $s3ClientRow = user_s3_job_client_row(is_array($s3Job) ? $s3Job : []);
                                                    $s3Pct = max(0, min(100, (float) $s3ClientRow['percent']));
                                                    $s3PctLabel = '';
                                                    if ($s3Status === 'uploading' && $s3Pct > 0) {
                                                        $s3PctLabel = ' ' . rtrim(rtrim(number_format($s3Pct, 1, '.', ''), '0'), '.') . '%';
                                                    }
                                                    ?>
                                                    <span class="sp-badge sp-badge-amber"><?php echo t('s3_vod_status_' . $s3Status); ?><?php echo htmlspecialchars($s3PctLabel); ?></span>
                                                <?php else: ?>
                                                    <form method="post" action="streaming.php#library" data-send-s3="1">
                                                        <input type="hidden" name="action" value="send_library_s3">
                                                        <input type="hidden" name="filename" value="<?php echo htmlspecialchars($file['name']); ?>">
                                                        <input type="hidden" name="file_title" value="<?php echo htmlspecialchars($displayTitle); ?>">
                                                        <button type="submit" class="sp-btn sp-btn-secondary sp-btn-sm"><?php echo $s3Status === 'failed' ? t('s3_vod_retry') : t('s3_vod_send'); ?></button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            —
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
</div>

<?php if ($canYoutube): ?>
<div class="stream-hub-panel" id="panel-import" data-stream-panel="import">
<div class="sp-card" id="import">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fab fa-twitch"></i> <?php echo t('youtube_vod_fetch_heading'); ?></div>
    </div>
    <div class="sp-card-body">
        <p class="sp-help"><?php echo t('youtube_vod_fetch_help'); ?></p>
        <div id="youtube-vod-import-notice" data-vod-notice></div>
        <div id="stream-hub-import-jobs" class="stream-hub-yt-jobs"><?php echo $ytProgressHtml; ?></div>
        <?php if ($twitchVideos): ?>
            <p class="sp-help"><?php echo htmlspecialchars($twitchVideosCapped
                ? t('youtube_vod_showing_capped', ['count' => (string) count($twitchVideos)])
                : t('youtube_vod_showing', ['count' => (string) count($twitchVideos)])); ?></p>
        <?php endif; ?>
        <?php if ($twitchVideosError): ?>
            <div class="sp-alert sp-alert-warning"><?php echo htmlspecialchars($twitchVideosError); ?></div>
        <?php elseif (!$twitchVideos): ?>
            <div class="stream-hub-empty"><i class="fab fa-twitch"></i><?php echo t('youtube_vod_fetch_empty'); ?></div>
        <?php else: ?>
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th><?php echo t('youtube_vod_th_title'); ?></th>
                                <th><?php echo t('youtube_vod_th_duration'); ?></th>
                                <th><?php echo t('youtube_vod_th_action'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($twitchVideos as $video): ?>
                                <?php
                                $vid = (string) ($video['id'] ?? '');
                                $vtitle = (string) ($video['title'] ?? '');
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
                                $ytJob = function_exists('youtube_job_for_twitch_id')
                                    ? youtube_job_for_twitch_id($youtubeJobs, $vid, $vtitle)
                                    : ($vid !== '' ? ($youtubeJobs[youtube_twitch_filename($vid)] ?? null) : null);
                                $ytStatus = is_array($ytJob) ? (string) ($ytJob['status'] ?? '') : '';
                                $ytLive = is_array($ytJob) && (!function_exists('youtube_job_is_live') || youtube_job_is_live($ytJob));
                                ?>
                                <tr data-vod-id="<?php echo htmlspecialchars($vid); ?>" data-vod-title="<?php echo htmlspecialchars($vtitle); ?>" data-vod-duration="<?php echo htmlspecialchars($vdur); ?>">
                                    <td><?php echo htmlspecialchars($vtitle); ?></td>
                                    <td><?php echo htmlspecialchars($vdur); ?></td>
                                    <td>
                                        <div class="stream-hub-file-actions">
                                        <span data-vod-store>
                                        <?php if ($pulling): ?>
                                            <span class="sp-badge sp-badge-amber"><?php echo t('youtube_vod_status_pulling'); ?></span>
                                        <?php elseif ($ready): ?>
                                            <span class="sp-badge sp-badge-green"><?php echo t('youtube_vod_status_stored'); ?></span>
                                        <?php elseif (!$isActAsUser): ?>
                                            <form method="post" action="streaming.php#library" data-store-vod="1">
                                                <input type="hidden" name="action" value="store_twitch_vod">
                                                <input type="hidden" name="vod_id" value="<?php echo htmlspecialchars($vid); ?>">
                                                <input type="hidden" name="vod_title" value="<?php echo htmlspecialchars($vtitle); ?>">
                                                <button type="submit" class="sp-btn sp-btn-primary sp-btn-sm"><?php echo t('youtube_vod_store_btn'); ?></button>
                                            </form>
                                        <?php endif; ?>
                                        </span>
                                        <?php if ($canUpload && !$isActAsUser && $vid !== ''): ?>
                                            <?php
                                            $durSeconds = youtube_parse_duration_seconds($vdur);
                                            $sizeBytes = null;
                                            if ($ready && is_array($stored)) {
                                                $storedSize = (int) ($stored['size'] ?? 0);
                                                if ($storedSize > 0) {
                                                    $sizeBytes = $storedSize;
                                                }
                                            }
                                            $ytLimit = youtube_upload_limit_reason($durSeconds, $sizeBytes);
                                            ?>
                                            <span data-vod-youtube>
                                            <?php if ($ytStatus === 'done'): ?>
                                                <span class="sp-badge sp-badge-green"><?php echo t('youtube_status_done'); ?></span>
                                            <?php elseif ($ytStatus === 'queued' || ($ytLive && in_array($ytStatus, ['uploading', 'pulling'], true))): ?>
                                                <?php
                                                $ytClient = youtube_job_client_row(is_array($ytJob) ? $ytJob : []);
                                                $ytPct = max(0, min(100, (float) $ytClient['percent']));
                                                $ytPctLabel = '';
                                                if ($ytStatus !== 'queued' && $ytPct > 0) {
                                                    $ytPctLabel = ' ' . rtrim(rtrim(number_format($ytPct, 1, '.', ''), '0'), '.') . '%';
                                                }
                                                ?>
                                                <span class="sp-badge sp-badge-amber"><?php echo t('youtube_status_' . $ytStatus); ?><?php echo htmlspecialchars($ytPctLabel); ?></span>
                                            <?php elseif ($ytLimit !== null): ?>
                                                <span class="youtube-upload-limit" title="<?php echo htmlspecialchars(t(youtube_upload_limit_lang_key($ytLimit))); ?>">
                                                    <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" disabled><?php echo t('videos_send_to_youtube'); ?></button>
                                                </span>
                                            <?php else: ?>
                                            <form method="post" action="streaming.php#import" data-send-youtube="1">
                                                <input type="hidden" name="action" value="send_twitch_youtube">
                                                <input type="hidden" name="vod_id" value="<?php echo htmlspecialchars($vid); ?>">
                                                <input type="hidden" name="vod_title" value="<?php echo htmlspecialchars($vtitle); ?>">
                                                <input type="hidden" name="vod_duration" value="<?php echo htmlspecialchars($vdur); ?>">
                                                <button type="submit" class="sp-btn sp-btn-secondary sp-btn-sm"><?php echo $ytStatus === 'failed' ? t('youtube_btn_retry') : t('videos_send_to_youtube'); ?></button>
                                            </form>
                                            <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                        </div>
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
<?php endif; ?>

<div class="stream-hub-panel" id="panel-setup" data-stream-panel="setup">
<div class="sp-two-col">
<div class="sp-card" id="record">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-video"></i> <?php echo t('recording_card_title'); ?></div>
    </div>
    <div class="sp-card-body">
        <p class="sp-help"><?php echo t('recording_auto_record_desc'); ?></p>
        <form method="post" action="streaming.php#record">
            <div class="sp-form-group">
                <label class="youtube-toggle">
                    <input type="checkbox" name="auto_record" <?php echo $autoRecordEnabled ? 'checked' : ''; ?>>
                    <?php echo t('recording_enable_channel_recording'); ?>
                </label>
            </div>
            <button type="submit" name="save_recording_settings" class="sp-btn sp-btn-primary sp-btn-sm"><?php echo t('recording_btn_save'); ?></button>
        </form>
    </div>
</div>

<div class="sp-card" id="youtube">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fab fa-youtube"></i> <?php echo t('youtube_link_page_title'); ?></div>
        <?php if ($canYoutube && $linked): ?>
            <span class="sp-badge sp-badge-green"><?php echo t('youtube_badge_connected'); ?></span>
        <?php elseif ($canYoutube && $needsReauth): ?>
            <span class="sp-badge sp-badge-amber"><?php echo t('youtube_badge_reauth'); ?></span>
        <?php elseif ($canYoutube): ?>
            <span class="sp-badge sp-badge-red"><?php echo t('youtube_badge_not_connected'); ?></span>
        <?php else: ?>
            <span class="sp-badge sp-badge-amber"><?php echo t('coming_soon'); ?></span>
        <?php endif; ?>
    </div>
    <div class="sp-card-body">
        <?php if (!$canYoutube): ?>
            <p class="sp-help"><?php echo t('youtube_public_coming_soon'); ?></p>
        <?php else: ?>
            <p class="sp-help"><?php echo t('youtube_link_intro'); ?></p>
            <?php if ($linked): ?>
                <div class="youtube-channel-row">
                    <?php if (!empty($linkRow['channel_thumbnail'])): ?>
                        <img class="youtube-channel-thumb" src="<?php echo htmlspecialchars($linkRow['channel_thumbnail']); ?>" alt="">
                    <?php else: ?>
                        <span class="youtube-channel-thumb youtube-channel-thumb-fallback"><i class="fab fa-youtube"></i></span>
                    <?php endif; ?>
                    <div>
                        <p class="youtube-channel-title"><?php echo htmlspecialchars((string) ($linkRow['channel_title'] ?? '')); ?></p>
                        <p class="sp-help"><?php echo t('youtube_channel_id_label'); ?> <code><?php echo htmlspecialchars((string) ($linkRow['channel_id'] ?? '')); ?></code></p>
                    </div>
                </div>
                <?php if (!$isActAsUser): ?>
                <form method="post" action="streaming.php#youtube" class="youtube-disconnect-form" onsubmit="return confirm(<?php echo json_encode(t('confirm_disconnect_youtube_text')); ?>);">
                    <input type="hidden" name="action" value="disconnect">
                    <button type="submit" class="sp-btn sp-btn-danger sp-btn-sm"><?php echo t('disconnect'); ?></button>
                </form>
                <?php endif; ?>
            <?php else: ?>
                <p><?php echo t('youtube_link_prompt'); ?></p>
                <?php if (!$isActAsUser && youtube_configured()): ?>
                    <a class="sp-btn sp-btn-primary" href="youtubelink.php?connect=1"><i class="fab fa-youtube"></i> <?php echo t('youtube_link_button'); ?></a>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</div>

<div class="sp-card" id="s3">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-database"></i> <?php echo t('s3_vod_card_title'); ?></div>
        <?php if (!empty($s3Public['connected'])): ?>
            <span class="sp-badge sp-badge-green"><?php echo t('s3_vod_badge_connected'); ?></span>
        <?php else: ?>
            <span class="sp-badge sp-badge-grey"><?php echo t('s3_vod_badge_not_connected'); ?></span>
        <?php endif; ?>
    </div>
    <div class="sp-card-body">
        <p class="sp-help"><?php echo t('s3_vod_help'); ?></p>
        <?php if ($isActAsUser): ?>
            <p class="sp-help"><?php echo t('s3_vod_actas_disabled'); ?></p>
        <?php else: ?>
            <form method="post" action="streaming.php#s3">
                <input type="hidden" name="action" value="save_s3_settings">
                <div class="sp-form-group">
                    <label class="sp-label" for="s3_endpoint"><?php echo t('s3_vod_endpoint_label'); ?></label>
                    <input class="sp-input w-100" id="s3_endpoint" name="s3_endpoint" type="text" value="<?php echo htmlspecialchars((string) ($s3Public['endpoint'] ?? '')); ?>" placeholder="https://s3.example.com" autocomplete="off">
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="s3_region"><?php echo t('s3_vod_region_label'); ?></label>
                    <input class="sp-input w-100" id="s3_region" name="s3_region" type="text" value="<?php echo htmlspecialchars((string) ($s3Public['region'] ?? '')); ?>" placeholder="us-east-1" autocomplete="off">
                    <span class="sp-help"><?php echo t('s3_vod_region_help'); ?></span>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="s3_bucket"><?php echo t('s3_vod_bucket_label'); ?></label>
                    <input class="sp-input w-100" id="s3_bucket" name="s3_bucket" type="text" value="<?php echo htmlspecialchars((string) ($s3Public['bucket'] ?? '')); ?>" autocomplete="off">
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="s3_prefix"><?php echo t('s3_vod_prefix_label'); ?></label>
                    <input class="sp-input w-100" id="s3_prefix" name="s3_prefix" type="text" value="<?php echo htmlspecialchars((string) ($s3Public['prefix'] ?? '')); ?>" placeholder="vods" autocomplete="off">
                    <span class="sp-help"><?php echo t('s3_vod_prefix_help'); ?></span>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="s3_access_key"><?php echo t('s3_vod_access_key_label'); ?></label>
                    <input class="sp-input w-100" id="s3_access_key" name="s3_access_key" type="text" value="<?php echo htmlspecialchars((string) ($s3Public['access_key'] ?? '')); ?>" autocomplete="off">
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="s3_secret_key"><?php echo t('s3_vod_secret_key_label'); ?></label>
                    <input class="sp-input w-100" id="s3_secret_key" name="s3_secret_key" type="password" value="" placeholder="<?php echo !empty($s3Public['secret_set']) ? htmlspecialchars(t('s3_vod_secret_kept', ['last4' => (string) ($s3Public['secret_last4'] ?? '')])) : ''; ?>" autocomplete="new-password">
                </div>
                <div class="sp-form-group">
                    <label class="youtube-toggle">
                        <input type="checkbox" name="s3_path_style" value="1" <?php echo !empty($s3Public['path_style']) || empty($s3Public['connected']) ? 'checked' : ''; ?>>
                        <?php echo t('s3_vod_path_style_label'); ?>
                    </label>
                    <span class="sp-help"><?php echo t('s3_vod_path_style_help'); ?></span>
                </div>
                <div class="sp-form-group">
                    <label class="youtube-toggle">
                        <input type="checkbox" name="s3_auto_copy" value="1" <?php echo !empty($s3Public['auto_copy']) ? 'checked' : ''; ?>>
                        <?php echo t('s3_vod_auto_copy_label'); ?>
                    </label>
                    <span class="sp-help"><?php echo t('s3_vod_auto_copy_help'); ?></span>
                </div>
                <button type="submit" class="sp-btn sp-btn-primary sp-btn-sm"><?php echo t('s3_vod_save'); ?></button>
            </form>
            <?php if (!empty($s3Public['connected']) && !$isActAsUser): ?>
                <form method="post" action="streaming.php#s3" class="youtube-disconnect-form" onsubmit="return confirm(<?php echo json_encode(t('s3_vod_disconnect_confirm')); ?>);">
                    <input type="hidden" name="action" value="disconnect_s3">
                    <button type="submit" class="sp-btn sp-btn-danger sp-btn-sm"><?php echo t('s3_vod_disconnect'); ?></button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="sp-two-col">
<div class="sp-card" id="ingest">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-satellite-dish"></i> <?php echo t('streaming_ingest_heading'); ?></div>
    </div>
    <div class="sp-card-body">
        <?php if (!$canIngest): ?>
            <h2><?php echo t('streaming_beta_title'); ?></h2>
            <p><?php echo t('streaming_beta_description'); ?></p>
            <p><?php echo t('streaming_beta_request_access'); ?></p>
            <a href="https://support.botofthespecter.com" target="_blank" rel="noopener noreferrer" class="sp-btn sp-btn-info">
                <span class="icon"><i class="fas fa-headset"></i></span>
                <span><?php echo t('streaming_beta_support_link'); ?></span>
            </a>
        <?php else: ?>
            <?php if ($hasSlot): ?>
                <div class="sp-alert sp-alert-info mb-4"><?php echo $storageUnlimited ? t('streaming_slot_unlimited') : t('streaming_slot_ok'); ?></div>
            <?php else: ?>
                <div class="sp-alert sp-alert-warning mb-4"><?php echo t('streaming_slot_none'); ?></div>
            <?php endif; ?>
            <p class="sp-help"><?php echo t('streaming_ingest_help'); ?></p>
            <div class="sp-form-group">
                <label class="sp-label" for="rtmps-server"><?php echo t('streaming_rtmps_server_label'); ?></label>
                <div class="sp-field-row">
                    <input class="sp-input w-100" id="rtmps-server" type="text" value="<?php echo htmlspecialchars($rtmpsUrl); ?>" readonly>
                    <button type="button" class="sp-btn sp-btn-secondary" data-copy-target="rtmps-server"><?php echo t('streaming_copy'); ?></button>
                </div>
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="specter-stream-key"><?php echo t('streaming_specter_key_label'); ?></label>
                <div class="sp-field-row">
                    <input class="sp-input w-100" id="specter-stream-key" type="password" value="<?php echo htmlspecialchars($specterKey); ?>" readonly autocomplete="off">
                    <button type="button" class="sp-btn sp-btn-secondary" id="toggle-specter-key" title="<?php echo htmlspecialchars(t('streaming_show_hide_twitch_key')); ?>"><i class="fas fa-eye"></i></button>
                    <button type="button" class="sp-btn sp-btn-secondary" data-copy-target="specter-stream-key"><?php echo t('streaming_copy'); ?></button>
                </div>
                <span class="sp-help"><?php echo t('streaming_api_key_note'); ?></span>
            </div>
            <form method="post" action="streaming.php#ingest">
                <div class="sp-form-group">
                    <label class="sp-label" for="twitch_key"><?php echo t('streaming_twitch_key_label'); ?></label>
                    <div class="sp-field-row">
                        <input class="sp-input w-100" id="twitch_key" name="twitch_key" type="password" value="<?php echo htmlspecialchars($twitchKey); ?>" autocomplete="off">
                        <button type="button" class="sp-btn sp-btn-secondary" id="toggle-twitch-key" title="<?php echo htmlspecialchars(t('streaming_show_hide_twitch_key')); ?>"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
                <div class="sp-form-group">
                    <label class="youtube-toggle">
                        <input type="checkbox" name="forward_to_twitch" value="1" <?php echo $forwardToTwitch ? 'checked' : ''; ?>>
                        <?php echo t('streaming_forward_to_twitch_label'); ?>
                    </label>
                </div>
                <button type="submit" name="save_streaming_settings" class="sp-btn sp-btn-primary"><?php echo t('streaming_save_settings_btn'); ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="sp-card" id="forward">
    <div class="sp-card-header">
        <div class="sp-card-title"><i class="fas fa-broadcast-tower"></i> <?php echo t('recording_forward_card_title'); ?></div>
    </div>
    <div class="sp-card-body">
        <p class="sp-help"><?php echo t('recording_forward_about_desc'); ?></p>
        <form method="post" action="streaming.php#forward">
            <div class="stream-hub-forward-grid">
            <?php foreach ($allowedForwardServices as $svc): ?>
                <?php
                $svcLabel = $forwardServiceLabels[$svc];
                $svcIcon = $forwardServiceIcons[$svc];
                $svcKey = htmlspecialchars($forwardSettings[$svc]['stream_key'] ?? '');
                $svcEnabled = (int) ($forwardSettings[$svc]['enabled'] ?? 0);
                ?>
                <div class="stream-hub-forward-block">
                    <div class="stream-hub-forward-head">
                        <?php if ($svcIcon['type'] === 'img'): ?>
                            <img src="<?php echo htmlspecialchars($svcIcon['value']); ?>" alt="" class="stream-hub-forward-icon">
                        <?php endif; ?>
                        <strong><?php echo htmlspecialchars($svcLabel); ?></strong>
                    </div>
                    <div class="sp-field-row">
                        <input type="password" id="forward_<?php echo $svc; ?>_key_input" name="forward_<?php echo $svc; ?>_key" value="<?php echo $svcKey; ?>" placeholder="<?php echo htmlspecialchars(t('recording_forward_stream_key_placeholder')); ?>" autocomplete="off" class="sp-input w-100">
                        <button type="button" class="sp-btn sp-btn-secondary" data-toggle-password="forward_<?php echo $svc; ?>_key_input"><i class="fas fa-eye"></i></button>
                    </div>
                    <label class="youtube-toggle">
                        <input type="checkbox" name="forward_<?php echo $svc; ?>_enabled" <?php echo $svcEnabled ? 'checked' : ''; ?>>
                        <?php echo t('recording_forward_enable_label'); ?>
                    </label>
                </div>
            <?php endforeach; ?>
            </div>
            <button type="submit" name="save_forward_settings" class="sp-btn sp-btn-primary sp-btn-sm"><?php echo t('recording_btn_save_forwarding'); ?></button>
        </form>
    </div>
</div>
</div>
</div>

<div class="sp-modal-backdrop" id="youtube-vod-links-modal">
    <div class="sp-modal sp-modal-wide" role="dialog" aria-labelledby="youtube-vod-links-title">
        <div class="sp-modal-head">
            <h2 class="sp-modal-title" id="youtube-vod-links-title"><?php echo t('youtube_vod_links_heading'); ?></h2>
            <button type="button" class="sp-modal-close" id="youtube-vod-links-close" aria-label="Close">&times;</button>
        </div>
        <div class="sp-modal-body">
            <p class="sp-help"><?php echo t('youtube_vod_links_help'); ?></p>
            <textarea class="sp-textarea youtube-vod-links-box" id="youtube-vod-links-box" readonly rows="10"></textarea>
            <div class="youtube-vod-toolbar">
                <button type="button" class="sp-btn sp-btn-primary sp-btn-sm" id="youtube-vod-links-copy"><?php echo t('youtube_vod_copy_links'); ?></button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var TAB_MAP = { library: 'library', import: 'import', setup: 'setup', record: 'setup', ingest: 'setup', forward: 'setup', youtube: 'setup', s3: 'setup' };
    function activateTab(name, scrollId) {
        var tab = TAB_MAP[name] || 'library';
        if (tab === 'import' && !document.querySelector('[data-stream-tab="import"]')) tab = 'library';
        document.querySelectorAll('#stream-hub-tabs li').forEach(function (li) {
            li.classList.toggle('is-active', li.getAttribute('data-stream-tab') === tab);
        });
        document.querySelectorAll('.stream-hub-panel').forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-stream-panel') === tab);
        });
        if (scrollId) {
            var target = document.getElementById(scrollId);
            if (target && target.scrollIntoView) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
    function tabFromHash() {
        var raw = (location.hash || '#library').replace('#', '');
        activateTab(raw, TAB_MAP[raw] === 'setup' && raw !== 'setup' ? raw : '');
    }
    document.getElementById('stream-hub-tabs') && document.getElementById('stream-hub-tabs').addEventListener('click', function (event) {
        var link = event.target.closest('a');
        var li = event.target.closest('li[data-stream-tab]');
        if (!li || !link) return;
        event.preventDefault();
        var tab = li.getAttribute('data-stream-tab');
        activateTab(tab);
        if (history.replaceState) history.replaceState(null, '', '#' + tab);
        else location.hash = tab;
    });
    window.addEventListener('hashchange', tabFromHash);
    tabFromHash();
    var I18N = {
        pulling: <?php echo json_encode(t('youtube_vod_status_pulling')); ?>,
        stored: <?php echo json_encode(t('youtube_vod_status_stored')); ?>,
        failed: <?php echo json_encode(t('youtube_vod_status_failed')); ?>,
        download: <?php echo json_encode(t('recording_btn_download')); ?>,
        extend: <?php echo json_encode(t('recording_btn_extend')); ?>,
        deleteFile: <?php echo json_encode(t('recording_btn_delete')); ?>,
        deleteTitle: <?php echo json_encode(t('recording_delete_title')); ?>,
        deleteText: <?php echo json_encode(t('recording_delete_text')); ?>,
        deleteConfirm: <?php echo json_encode(t('recording_delete_confirm')); ?>,
        deleteCancel: <?php echo json_encode(t('recording_delete_cancel')); ?>,
        deleteFailed: <?php echo json_encode(t('recording_delete_failed')); ?>,
        deleteBusy: <?php echo json_encode(t('recording_delete_in_progress')); ?>,
        inProgress: <?php echo json_encode(t('recording_type_in_progress')); ?>,
        storing: <?php echo json_encode(t('youtube_vod_status_pulling')); ?>,
        recorded: <?php echo json_encode(t('recording_type_recorded')); ?>,
        storedType: <?php echo json_encode(t('recording_type_stored')); ?>,
        extended: <?php echo json_encode(t('recording_type_extended')); ?>,
        file: <?php echo json_encode(t('recording_type_file')); ?>,
        sendYoutube: <?php echo json_encode(t('videos_send_to_youtube')); ?>,
        retryYoutube: <?php echo json_encode(t('youtube_btn_retry')); ?>,
        ytQueued: <?php echo json_encode(t('youtube_status_queued')); ?>,
        ytPulling: <?php echo json_encode(t('youtube_status_pulling')); ?>,
        ytUploading: <?php echo json_encode(t('youtube_status_uploading')); ?>,
        ytDone: <?php echo json_encode(t('youtube_status_done')); ?>,
        ytTooLong: <?php echo json_encode(t('youtube_upload_too_long')); ?>,
        ytTooLarge: <?php echo json_encode(t('youtube_upload_too_large')); ?>,
        ytSendFailed: <?php echo json_encode(t('youtube_vod_youtube_failed')); ?>,
        noFiles: <?php echo json_encode(t('recording_error_no_files')); ?>,
        expired: <?php echo json_encode(t('recording_countdown_expired')); ?>,
        extendFailed: <?php echo json_encode(t('recording_extend_failed')); ?>,
        dismiss: <?php echo json_encode(t('layout_close')); ?>,
        storeFailed: <?php echo json_encode(t('youtube_vod_store_failed')); ?>,
        copyLinks: <?php echo json_encode(t('youtube_vod_copy_links')); ?>,
        linksNone: <?php echo json_encode(t('youtube_vod_links_none')); ?>,
        linksCopied: <?php echo json_encode(t('youtube_vod_links_copied')); ?>,
        storageUsedOf: <?php echo json_encode(t('recording_storage_used_of')); ?>,
        storageUsedUnlimited: <?php echo json_encode(t('recording_storage_used_unlimited')); ?>,
        sendS3: <?php echo json_encode(t('s3_vod_send')); ?>,
        retryS3: <?php echo json_encode(t('s3_vod_retry')); ?>,
        s3Queued: <?php echo json_encode(t('s3_vod_status_queued')); ?>,
        s3Uploading: <?php echo json_encode(t('s3_vod_status_uploading')); ?>,
        s3Done: <?php echo json_encode(t('s3_vod_status_done')); ?>,
        s3SendFailed: <?php echo json_encode(t('s3_vod_send_failed')); ?>
    };
    var helixTitles = <?php echo json_encode($helixTitles, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    var helixDurations = <?php echo json_encode($helixDurations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    var canUpload = <?php echo ($canUpload && !$isActAsUser) ? 'true' : 'false'; ?>;
    var youtubeJobs = <?php echo json_encode(array_map('youtube_job_client_row', $youtubeJobs), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    var canS3 = <?php echo (!empty($canS3)) ? 'true' : 'false'; ?>;
    var s3Jobs = <?php echo json_encode($s3Jobs ? array_map('user_s3_job_client_row', $s3Jobs) : new stdClass(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    var YT_MAX_SECONDS = 12 * 3600;
    var YT_MAX_BYTES = 256 * 1024 * 1024 * 1024;
    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[<>&"]/g, function (ch) {
            return ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' })[ch];
        });
    }
    function formatBytes(bytes) {
        bytes = Number(bytes) || 0;
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
        return (bytes / 1073741824).toFixed(1) + ' GB';
    }
    function displayName(file) {
        var title = String((file && file.title) || '').trim();
        if (title) return title.replace(/\.mp4$/i, '');
        var id = file && file.twitch_video_id ? String(file.twitch_video_id) : '';
        if (!id && file && file.name) {
            var m = String(file.name).match(/^twitch-([0-9]{1,20})\.mp4/i);
            if (m) id = m[1];
        }
        if (id && helixTitles[id]) return String(helixTitles[id]);
        return String((file && file.name) || '').replace(/\.mp4(\.part)?$/i, '');
    }
    function twitchIdOf(file) {
        var id = file && file.twitch_video_id ? String(file.twitch_video_id) : '';
        if (!id && file && file.name) {
            var m = String(file.name).match(/^twitch-([0-9]{1,20})\.mp4/i);
            if (m) id = m[1];
        }
        return id;
    }
    function fileMtime(file) {
        if (file && file.modified) return Number(file.modified) || 0;
        if (file && file.modified_at) {
            var parsed = Date.parse(file.modified_at);
            return isFinite(parsed) ? parsed / 1000 : 0;
        }
        return 0;
    }
    function fileKind(file) {
        var isTwitch = twitchIdOf(file) !== '';
        if (file && file.is_partial) return isTwitch ? 'storing' : 'recording';
        if (isTwitch) return 'stored';
        if (file && file.storage === 's4') return 'recorded';
        var mt = fileMtime(file);
        var age = (Date.now() / 1000) - mt;
        if (mt && age >= 0 && age < 180) return 'recording';
        return 'recorded';
    }
    function parseDurationSeconds(value) {
        if (value == null || value === '') return null;
        if (typeof value === 'number' && isFinite(value)) return value >= 0 ? Math.round(value) : null;
        var s = String(value).trim().toUpperCase();
        if (!s) return null;
        if (/^\d+(\.\d+)?$/.test(s)) return Math.round(Number(s));
        var iso = s.match(/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/);
        if (iso && (iso[1] || iso[2] || iso[3])) {
            return Math.round((Number(iso[1] || 0) * 3600) + (Number(iso[2] || 0) * 60) + Number(iso[3] || 0));
        }
        var tw = s.match(/^(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/);
        if (tw && (tw[1] || tw[2] || tw[3])) {
            return (Number(tw[1] || 0) * 3600) + (Number(tw[2] || 0) * 60) + Number(tw[3] || 0);
        }
        return null;
    }
    function youtubeLimitReason(durationS, sizeBytes) {
        if (durationS != null && durationS > YT_MAX_SECONDS) return 'too_long';
        if (sizeBytes != null && sizeBytes > YT_MAX_BYTES) return 'too_large';
        return null;
    }
    function typeBadgesHtml(file) {
        var kind = fileKind(file);
        var html = '<div class="stream-hub-type">';
        if (kind === 'recording') html += '<span class="sp-badge sp-badge-amber">' + escapeHtml(I18N.inProgress) + '</span>';
        else if (kind === 'storing') html += '<span class="sp-badge sp-badge-amber">' + escapeHtml(I18N.storing) + '</span>';
        else if (kind === 'stored') html += '<span class="sp-badge sp-badge-blue">' + escapeHtml(I18N.storedType) + '</span>';
        else html += '<span class="sp-badge sp-badge-accent">' + escapeHtml(I18N.recorded) + '</span>';
        if (file && file.storage === 's4' && kind !== 'recording' && kind !== 'storing') {
            html += '<span class="sp-badge sp-badge-grey">' + escapeHtml(I18N.extended) + '</span>';
        }
        return html + '</div>';
    }
    function youtubeJobLive(job) {
        if (!job) return false;
        var status = String(job.status || '');
        if (status !== 'uploading' && status !== 'pulling') return false;
        if (typeof job.live === 'boolean') return job.live;
        var ts = Number(job.updated_unix || 0);
        if (!ts) return true;
        return (Date.now() / 1000 - ts) < 30 * 60;
    }
    function youtubeJobForVod(vodId, title) {
        vodId = String(vodId || '');
        title = String(title || '').trim().toLowerCase();
        var jobs = youtubeJobs || {};
        var found = null;
        if (vodId) {
            var fileKey = 'twitch-' + vodId + '.mp4';
            if (jobs[fileKey]) return jobs[fileKey];
            Object.keys(jobs).forEach(function (name) {
                if (String(jobs[name].twitch_video_id || '') === vodId) found = jobs[name];
            });
            if (found) return found;
        }
        if (title) {
            Object.keys(jobs).forEach(function (name) {
                if (String(jobs[name].title || '').trim().toLowerCase() === title) found = jobs[name];
            });
        }
        return found || {};
    }
    function youtubeStatusHtml(job) {
        var status = String((job && job.status) || '');
        if ((status === 'uploading' || status === 'pulling') && !youtubeJobLive(job)) return '';
        var pct = Number(job && job.percent);
        var extra = (status !== 'queued' && isFinite(pct) && pct > 0) ? (' ' + (Math.round(pct * 10) / 10) + '%') : '';
        if (status === 'done') return '<span class="sp-badge sp-badge-green">' + escapeHtml(I18N.ytDone) + '</span>';
        if (status === 'queued') return '<span class="sp-badge sp-badge-amber">' + escapeHtml(I18N.ytQueued) + '</span>';
        if (status === 'pulling') return '<span class="sp-badge sp-badge-amber">' + escapeHtml(I18N.ytPulling + extra) + '</span>';
        if (status === 'uploading') return '<span class="sp-badge sp-badge-amber">' + escapeHtml(I18N.ytUploading + extra) + '</span>';
        return '';
    }
    function importYoutubeActionHtml(vodId, title, duration) {
        if (!canUpload || !vodId) return '';
        var job = youtubeJobForVod(vodId, title);
        var badge = youtubeStatusHtml(job);
        if (badge) return badge;
        var status = String(job.status || '');
        if ((status === 'uploading' || status === 'pulling') && !youtubeJobLive(job)) status = 'failed';
        var limit = youtubeLimitReason(parseDurationSeconds(duration), null);
        if (limit) {
            var why = limit === 'too_large' ? I18N.ytTooLarge : I18N.ytTooLong;
            return '<span class="youtube-upload-limit" title="' + escapeHtml(why) + '"><button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" disabled>' + escapeHtml(I18N.sendYoutube) + '</button></span>';
        }
        var label = status === 'failed' ? I18N.retryYoutube : I18N.sendYoutube;
        return '<form method="post" action="streaming.php#import" data-send-youtube="1">'
            + '<input type="hidden" name="action" value="send_twitch_youtube">'
            + '<input type="hidden" name="vod_id" value="' + escapeHtml(vodId) + '">'
            + '<input type="hidden" name="vod_title" value="' + escapeHtml(title || '') + '">'
            + '<input type="hidden" name="vod_duration" value="' + escapeHtml(duration || '') + '">'
            + '<button type="submit" class="sp-btn sp-btn-secondary sp-btn-sm">' + escapeHtml(label) + '</button></form>';
    }
    function updateImportYoutubeCells() {
        document.querySelectorAll('tr[data-vod-id] [data-vod-youtube]').forEach(function (cell) {
            if (cell.querySelector('.sp-btn-loading')) return;
            var row = cell.closest('tr[data-vod-id]');
            if (!row) return;
            cell.innerHTML = importYoutubeActionHtml(
                row.getAttribute('data-vod-id') || '',
                row.getAttribute('data-vod-title') || '',
                row.getAttribute('data-vod-duration') || ''
            );
        });
    }
    function youtubeActionHtml(file, title) {
        if (!canUpload || !file || file.is_partial || fileKind(file) === 'recording' || fileKind(file) === 'storing' || !/\.mp4$/i.test(String(file.name || ''))) return '';
        var job = youtubeJobs[file.name] || youtubeJobForVod(twitchIdOf(file), title);
        var status = String(job.status || '');
        if ((status === 'uploading' || status === 'pulling') && !youtubeJobLive(job)) status = 'failed';
        var badge = youtubeStatusHtml(job);
        if (badge) return badge;
        var tid = twitchIdOf(file);
        var size = Number(file.size || file.size_bytes || 0) || 0;
        var limit = youtubeLimitReason(parseDurationSeconds(helixDurations[tid] || ''), size > 0 ? size : null);
        if (limit) {
            var why = limit === 'too_large' ? I18N.ytTooLarge : I18N.ytTooLong;
            return '<span class="youtube-upload-limit" title="' + escapeHtml(why) + '"><button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" disabled>' + escapeHtml(I18N.sendYoutube) + '</button></span>';
        }
        var label = status === 'failed' ? I18N.retryYoutube : I18N.sendYoutube;
        return '<form method="post" action="streaming.php#library" data-send-youtube="1">'
            + '<input type="hidden" name="action" value="send_library_youtube">'
            + '<input type="hidden" name="filename" value="' + escapeHtml(file.name || '') + '">'
            + '<input type="hidden" name="file_title" value="' + escapeHtml(title || '') + '">'
            + '<input type="hidden" name="twitch_video_id" value="' + escapeHtml(tid) + '">'
            + '<input type="hidden" name="file_size" value="' + size + '">'
            + '<input type="hidden" name="file_duration" value="' + escapeHtml(helixDurations[tid] || '') + '">'
            + '<button type="submit" class="sp-btn sp-btn-secondary sp-btn-sm">' + escapeHtml(label) + '</button></form>';
    }
    function namedDownloadUrl(url, title, diskName) {
        url = String(url || '').split('?')[0];
        if (!url) return '';
        if (/\/[^/]+\.mp4\/[^/]+\.mp4$/i.test(url)) return url;
        var pretty = String(title || diskName || 'video').replace(/[\x00-\x1f\x7f<>:"/\\|?*]/g, '-').replace(/\s+/g, ' ').trim();
        if (!pretty) pretty = 'video';
        if (!/\.mp4$/i.test(pretty)) pretty += '.mp4';
        return url.replace(/\/?$/, '') + '/' + encodeURIComponent(pretty);
    }
    function uniqueNamedUrls(items) {
        var used = {};
        return items.map(function (item) {
            var url = namedDownloadUrl(item.url, item.title, item.name);
            var parts = url.split('/');
            var pretty = decodeURIComponent(parts[parts.length - 1] || 'video.mp4');
            var stem = pretty.replace(/\.mp4$/i, '');
            var key = pretty.toLowerCase();
            var n = 2;
            while (used[key]) {
                pretty = stem + ' (' + n + ').mp4';
                key = pretty.toLowerCase();
                n += 1;
            }
            used[key] = true;
            parts[parts.length - 1] = encodeURIComponent(pretty);
            return parts.join('/');
        });
    }
    function formatCountdown(expiresUnix) {
        var remaining = Math.floor(expiresUnix - (Date.now() / 1000));
        if (remaining <= 0) return I18N.expired;
        var days = Math.floor(remaining / 86400);
        var hours = Math.floor((remaining % 86400) / 3600);
        var mins = Math.floor((remaining % 3600) / 60);
        var secs = remaining % 60;
        var pad = function (v) { return String(v).padStart(2, '0'); };
        if (days > 0) return days + 'd ' + pad(hours) + 'h ' + pad(mins) + 'm';
        if (hours > 0) return hours + 'h ' + pad(mins) + 'm ' + pad(secs) + 's';
        return mins + 'm ' + pad(secs) + 's';
    }
    function tickCountdowns() {
        document.querySelectorAll('.recording-countdown').forEach(function (el) {
            var expires = Number(el.getAttribute('data-expires') || 0);
            if (expires) el.textContent = formatCountdown(expires);
        });
    }
    function showToast(message, kind) {
        var host = document.getElementById('sp-toast-container');
        if (!host) {
            host = document.createElement('div');
            host.id = 'sp-toast-container';
            host.style.cssText = 'position:fixed;top:1.25rem;right:1.25rem;z-index:9999;display:flex;flex-direction:column;gap:0.5rem;max-width:420px;width:calc(100% - 2.5rem);pointer-events:none;';
            document.body.appendChild(host);
        }
        var note = document.createElement('div');
        var cls = kind === 'success' ? 'sp-alert-success' : kind === 'warning' ? 'sp-alert-warning' : 'sp-alert-danger';
        note.className = 'sp-alert ' + cls + ' sp-notif';
        note.style.cssText = 'display:flex;align-items:flex-start;gap:0.5rem;pointer-events:auto;box-shadow:0 4px 14px rgba(0,0,0,0.3);margin:0;';
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'sp-notif-close';
        close.setAttribute('aria-label', I18N.dismiss || 'close');
        close.textContent = '\u00d7';
        close.addEventListener('click', function () { note.remove(); });
        var text = document.createElement('span');
        text.textContent = message || '';
        note.appendChild(close);
        note.appendChild(text);
        host.appendChild(note);
        setTimeout(function () { if (note.parentNode) note.remove(); }, 5000);
    }
    function setNotice(message, kind) {
        document.querySelectorAll('[data-vod-notice]').forEach(function (el) {
            if (!message) { el.innerHTML = ''; return; }
            var cls = kind === 'success' ? 'sp-alert-success' : kind === 'warning' ? 'sp-alert-warning' : kind === 'danger' ? 'sp-alert-danger' : 'sp-alert-info';
            el.innerHTML = '<div class="sp-alert ' + cls + '"></div>';
            el.firstChild.textContent = message;
        });
    }
    function syncCopyBtn() {
        var btn = document.getElementById('youtube-vod-copy-links');
        if (btn) btn.disabled = document.querySelectorAll('.youtube-vod-pick:checked').length === 0;
    }
    function updateStorageBar(storage) {
        if (!storage) return;
        var used = Number(storage.used_bytes) || 0;
        var quota = Number(storage.quota_bytes) || 0;
        var unlimited = !!storage.unlimited || quota === 0;
        var text = document.getElementById('recording-storage-text');
        var bar = document.getElementById('recording-storage-progress');
        if (text) {
            text.textContent = unlimited
                ? I18N.storageUsedUnlimited.replace('%s', formatBytes(used))
                : I18N.storageUsedOf.replace('%s', formatBytes(used)).replace('%s', formatBytes(quota));
        }
        if (bar) {
            var visual = unlimited ? (100 * 1024 * 1024 * 1024) : Math.max(quota, 1);
            bar.value = Math.min(100, Math.round((used / visual) * 1000) / 10);
        }
    }
    function fillStoreCell(cell, mode) {
        if (!cell) return;
        if (mode === 'pulling') cell.innerHTML = '<span class="sp-badge sp-badge-amber">' + escapeHtml(I18N.pulling) + '</span>';
        if (mode === 'stored') cell.innerHTML = '<span class="sp-badge sp-badge-green">' + escapeHtml(I18N.stored) + '</span>';
    }
    function renderUploadBars() {
        var html = '';
        Object.keys(youtubeJobs || {}).forEach(function (name) {
            var job = youtubeJobs[name];
            if (!job || (job.status !== 'uploading' && job.status !== 'pulling') || !youtubeJobLive(job)) return;
            var pct = Number(job.percent);
            if (!isFinite(pct)) pct = 0;
            pct = Math.max(0, Math.min(100, pct));
            var title = job.title || name;
            var sent = Number(job.bytes_sent) || 0;
            var total = Number(job.bytes_total) || 0;
            var phase = job.status === 'pulling' ? I18N.ytPulling : I18N.ytUploading;
            var right = (Math.round(pct * 10) / 10) + '%';
            if (job.status === 'uploading' && total > 0) right += ' · ' + formatBytes(sent) + ' / ' + formatBytes(total);
            else if (job.status === 'pulling' && sent > 0) right += ' · ' + formatBytes(sent);
            html += '<div class="media-storage-bar mb-4 stream-hub-upload-bar"><div class="media-storage-header"><span>'
                + escapeHtml(title) + ' — ' + escapeHtml(phase)
                + '</span><span>' + escapeHtml(right) + '</span></div><progress class="progress" value="'
                + pct + '" max="100"></progress></div>';
        });
        document.querySelectorAll('.stream-hub-yt-jobs').forEach(function (host) {
            host.innerHTML = html;
        });
        var s3html = '';
        Object.keys(s3Jobs || {}).forEach(function (name) {
            var job = s3Jobs[name];
            if (!job) return;
            var status = String(job.status || '');
            var live = job.live !== false;
            if (status === 'queued') {
                s3html += '<div class="media-storage-bar mb-4 stream-hub-upload-bar"><div class="media-storage-header"><span>'
                    + escapeHtml(job.title || name) + ' — ' + escapeHtml(I18N.s3Queued)
                    + '</span><span></span></div></div>';
                return;
            }
            if (status !== 'uploading' || !live) return;
            var pct = Number(job.percent);
            if (!isFinite(pct)) pct = 0;
            pct = Math.max(0, Math.min(100, pct));
            var sent = Number(job.bytes_sent) || 0;
            var total = Number(job.bytes_total) || 0;
            var right = (Math.round(pct * 10) / 10) + '%';
            if (total > 0) right += ' · ' + formatBytes(sent) + ' / ' + formatBytes(total);
            s3html += '<div class="media-storage-bar mb-4 stream-hub-upload-bar"><div class="media-storage-header"><span>'
                + escapeHtml(job.title || name) + ' — ' + escapeHtml(I18N.s3Uploading)
                + '</span><span>' + escapeHtml(right) + '</span></div><progress class="progress" value="'
                + pct + '" max="100"></progress></div>';
        });
        document.querySelectorAll('.stream-hub-s3-jobs').forEach(function (host) {
            host.innerHTML = s3html;
        });
    }
    function s3JobLive(job) {
        if (!job) return false;
        if (job.status === 'queued') return true;
        if (job.status !== 'uploading') return false;
        if (typeof job.live === 'boolean') return job.live;
        return true;
    }
    function s3ActionHtml(file, title) {
        if (!canS3 || !file || file.is_partial || fileKind(file) === 'recording' || fileKind(file) === 'storing' || !/\.mp4$/i.test(String(file.name || ''))) return '';
        var job = s3Jobs[file.name] || {};
        var status = String(job.status || '');
        if (status === 'done') return '<span class="sp-badge sp-badge-green">' + escapeHtml(I18N.s3Done) + '</span>';
        if (status === 'queued') return '<span class="sp-badge sp-badge-amber">' + escapeHtml(I18N.s3Queued) + '</span>';
        if (status === 'uploading' && s3JobLive(job)) {
            var pct = Number(job.percent);
            var extra = (isFinite(pct) && pct > 0) ? (' ' + (Math.round(pct * 10) / 10) + '%') : '';
            return '<span class="sp-badge sp-badge-amber">' + escapeHtml(I18N.s3Uploading + extra) + '</span>';
        }
        var label = status === 'failed' ? I18N.retryS3 : I18N.sendS3;
        return '<form method="post" action="streaming.php#library" data-send-s3="1">'
            + '<input type="hidden" name="action" value="send_library_s3">'
            + '<input type="hidden" name="filename" value="' + escapeHtml(file.name || '') + '">'
            + '<input type="hidden" name="file_title" value="' + escapeHtml(title || '') + '">'
            + '<button type="submit" class="sp-btn sp-btn-secondary sp-btn-sm">' + escapeHtml(label) + '</button></form>';
    }
    function render(data) {
        if (!data) return;
        if (data.youtube_jobs && typeof data.youtube_jobs === 'object') youtubeJobs = data.youtube_jobs;
        if (typeof data.can_upload === 'boolean') canUpload = data.can_upload;
        if (data.s3_jobs && typeof data.s3_jobs === 'object') s3Jobs = data.s3_jobs;
        if (typeof data.can_s3 === 'boolean') canS3 = data.can_s3;
        renderUploadBars();
        updateImportYoutubeCells();
        updateStorageBar(data.storage);
        var filesStat = document.getElementById('stream-hub-stat-files');
        var pullStat = document.getElementById('stream-hub-stat-pulling');
        var usedStat = document.getElementById('stream-hub-stat-used');
        if (filesStat) filesStat.textContent = String((data.files || []).length);
        if (pullStat) {
            var pullingN = (data.pulls || []).filter(function (j) { return j && j.status === 'pulling'; }).length;
            Object.keys(youtubeJobs || {}).forEach(function (name) {
                if (youtubeJobs[name] && youtubeJobs[name].status === 'pulling') pullingN += 1;
            });
            pullStat.textContent = String(pullingN);
            var pullCard = pullStat.closest('.sp-stat');
            if (pullCard) pullCard.classList.toggle('warn', pullingN > 0);
        }
        if (usedStat && data.storage) usedStat.textContent = formatBytes(data.storage.used_bytes || 0);
        var pullsHost = document.getElementById('stream-hub-pulls');
        var filesHost = document.getElementById('remote-files-container');
        var pulls = Array.isArray(data.pulls) ? data.pulls : [];
        var files = Array.isArray(data.files) ? data.files : [];
        var pullingIds = {};
        var storedIds = {};
        var html = '';
        pulls.filter(function (j) { return j && j.status === 'pulling'; }).forEach(function (job) {
            if (job.vod_id) pullingIds[String(job.vod_id)] = true;
            var pct = (typeof job.percent === 'number') ? Math.max(0, Math.min(100, job.percent)) : 0;
            var label = job.title || job.filename || job.vod_id || '';
            var pctLabel = (typeof job.percent === 'number') ? (pct.toFixed(1) + '%') : I18N.pulling;
            html += '<div class="media-storage-bar mb-4"><div class="media-storage-header"><span>' + escapeHtml(label) + '</span><span>' + escapeHtml(pctLabel) + '</span></div><progress class="progress" value="' + pct + '" max="100"></progress></div>';
        });
        pulls.filter(function (j) { return j && j.status === 'failed'; }).forEach(function (job) {
            html += '<div class="sp-alert sp-alert-danger mb-4">' + escapeHtml(job.title || job.filename || '') + ' — ' + escapeHtml(I18N.failed) + '</div>';
        });
        if (pullsHost) pullsHost.innerHTML = html;
        files.forEach(function (file) {
            var id = file.twitch_video_id ? String(file.twitch_video_id) : '';
            if (!id && file.name) {
                var m = String(file.name).match(/^twitch-([0-9]{1,20})\.mp4/i);
                if (m) id = m[1];
            }
            if (id && !file.is_partial) storedIds[id] = true;
        });
        document.querySelectorAll('tr[data-vod-id] [data-vod-store]').forEach(function (cell) {
            var row = cell.closest('tr[data-vod-id]');
            var id = row ? row.getAttribute('data-vod-id') : '';
            if (id && pullingIds[id]) fillStoreCell(cell, 'pulling');
            else if (id && storedIds[id]) fillStoreCell(cell, 'stored');
        });
        if (!filesHost) return;
        var selected = {};
        document.querySelectorAll('.youtube-vod-pick:checked').forEach(function (box) {
            selected[box.getAttribute('data-vod-url') || ''] = true;
        });
        if (data.remoteFileError) {
            filesHost.innerHTML = '<div class="sp-alert sp-alert-warning">' + escapeHtml(data.remoteFileError) + '</div>';
            return;
        }
        if (!files.length) {
            filesHost.innerHTML = '<div class="stream-hub-empty"><i class="fas fa-folder-open"></i>' + escapeHtml(I18N.noFiles) + '</div>';
            syncCopyBtn();
            return;
        }
        var rows = '';
        files.forEach(function (file) {
            var title = displayName(file);
            var named = namedDownloadUrl(file.download_url || '', title, file.name || '');
            var kind = fileKind(file);
            var inProgress = kind === 'recording' || kind === 'storing' || !!file.is_partial;
            var canDl = !inProgress && /\.mp4$/i.test(String(file.name || ''));
            var type = typeBadgesHtml(file);
            var check = (canDl && named) ? '<input type="checkbox" class="youtube-vod-check youtube-vod-pick" data-vod-url="' + escapeHtml(named) + '" data-vod-title="' + escapeHtml(title) + '" data-vod-name="' + escapeHtml(file.name || '') + '">' : '';
            var actions = '—';
            if (canDl && named) {
                actions = '<div class="stream-hub-file-actions"><a class="sp-btn sp-btn-primary sp-btn-sm" href="' + escapeHtml(named) + '">' + escapeHtml(I18N.download) + '</a>';
                if (file.can_extend) {
                    actions += '<button type="button" class="sp-btn sp-btn-secondary sp-btn-sm" data-extend-file="' + escapeHtml(file.name || '') + '">' + escapeHtml(I18N.extend) + '</button>';
                }
                actions += '<button type="button" class="sp-btn sp-btn-danger sp-btn-sm" data-delete-file="' + escapeHtml(file.name || '') + '" data-delete-title="' + escapeHtml(title) + '">' + escapeHtml(I18N.deleteFile) + '</button>';
                actions += youtubeActionHtml(file, title);
                actions += s3ActionHtml(file, title);
                actions += '</div>';
            }
            var expires = Number(file.expires_unix || file.expires_at_unix || 0);
            var expCell = expires ? '<span class="recording-countdown" data-expires="' + expires + '">—</span>' : '—';
            rows += '<tr><td>' + check + '</td><td>' + escapeHtml(title) + '</td><td>' + type + '</td><td>' + formatBytes(file.size || file.size_bytes || 0) + '</td><td>' + expCell + '</td><td>' + actions + '</td></tr>';
        });
        filesHost.innerHTML = '<div class="sp-table-wrap"><table class="sp-table"><thead><tr><th><input type="checkbox" class="youtube-vod-check" id="youtube-vod-select-all"></th><th><?php echo htmlspecialchars(t('recording_th_file')); ?></th><th><?php echo htmlspecialchars(t('recording_th_type')); ?></th><th><?php echo htmlspecialchars(t('recording_th_size')); ?></th><th><?php echo htmlspecialchars(t('recording_th_expires')); ?></th><th><?php echo htmlspecialchars(t('recording_th_action')); ?></th></tr></thead><tbody>' + rows + '</tbody></table></div>';
        document.querySelectorAll('.youtube-vod-pick').forEach(function (box) {
            if (selected[box.getAttribute('data-vod-url') || '']) box.checked = true;
        });
        tickCountdowns();
        syncCopyBtn();
    }
    var pollSeq = 0;
    function poll() {
        var seq = ++pollSeq;
        fetch('streaming.php?ajax=vods&_ts=' + Date.now(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (seq !== pollSeq) return;
                render(data);
            })
            .catch(function () {});
    }
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var isStore = form && form.getAttribute('data-store-vod') === '1';
        var isSend = form && form.getAttribute('data-send-youtube') === '1';
        var isSendS3 = form && form.getAttribute('data-send-s3') === '1';
        if (!isStore && !isSend && !isSendS3) return;
        event.preventDefault();
        var btn = form.querySelector('button[type="submit"]');
        if (btn) { btn.disabled = true; btn.classList.add('sp-btn-loading'); }
        fetch('streaming.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: new FormData(form)
        }).then(function (response) {
            return response.json().then(function (json) { return json || {}; }).catch(function () { return {}; });
        }).then(function (json) {
            var ok = json.ok === true;
            if (isSendS3) {
                setNotice(json.message || (ok ? '' : I18N.s3SendFailed), ok ? 'success' : 'warning');
                if (ok) {
                    pollSeq += 1;
                    var fileInput = form.querySelector('input[name="filename"]');
                    var titleInput = form.querySelector('input[name="file_title"]');
                    var name = (json.filename || (fileInput && fileInput.value) || '').trim();
                    if (name) {
                        s3Jobs[name] = s3Jobs[name] || {};
                        s3Jobs[name].status = json.status || 'queued';
                        s3Jobs[name].filename = name;
                        if (titleInput && titleInput.value) s3Jobs[name].title = titleInput.value;
                        if (s3Jobs[name].percent == null) s3Jobs[name].percent = 0;
                        s3Jobs[name].live = true;
                    }
                    renderUploadBars();
                    poll();
                } else if (btn) { btn.disabled = false; btn.classList.remove('sp-btn-loading'); }
                return;
            }
            if (isSend) {
                setNotice(json.message || (ok ? '' : I18N.ytSendFailed), ok ? 'success' : 'warning');
                if (ok) {
                    pollSeq += 1;
                    var vodInput = form.querySelector('input[name="vod_id"]');
                    var fileInput = form.querySelector('input[name="filename"]');
                    var titleInput = form.querySelector('input[name="vod_title"], input[name="file_title"]');
                    var name = (json.filename || (fileInput && fileInput.value) || (vodInput && vodInput.value ? ('twitch-' + vodInput.value + '.mp4') : '')).trim();
                    if (name) {
                        youtubeJobs[name] = youtubeJobs[name] || {};
                        youtubeJobs[name].status = json.status || 'queued';
                        youtubeJobs[name].filename = name;
                        if (titleInput && titleInput.value) youtubeJobs[name].title = titleInput.value;
                        if (vodInput && vodInput.value) youtubeJobs[name].twitch_video_id = vodInput.value;
                        if (youtubeJobs[name].percent == null) youtubeJobs[name].percent = 0;
                    }
                    renderUploadBars();
                    updateImportYoutubeCells();
                    poll();
                } else if (btn) { btn.disabled = false; btn.classList.remove('sp-btn-loading'); }
                return;
            }
            setNotice(json.message || (ok ? '' : I18N.storeFailed), ok ? 'success' : (json.status === 'full' ? 'warning' : 'danger'));
            if (ok) {
                fillStoreCell(form.closest('[data-vod-store]'), json.status === 'stored' ? 'stored' : 'pulling');
                activateTab('library');
                if (history.replaceState) history.replaceState(null, '', '#library');
                poll();
            } else if (btn) {
                btn.disabled = false;
                btn.classList.remove('sp-btn-loading');
            }
        }).catch(function () {
            setNotice(isSendS3 ? I18N.s3SendFailed : (isSend ? I18N.ytSendFailed : I18N.storeFailed), 'danger');
            if (btn) { btn.disabled = false; btn.classList.remove('sp-btn-loading'); }
        });
    });
    document.addEventListener('change', function (event) {
        var target = event.target;
        if (!target) return;
        if (target.id === 'youtube-vod-select-all') {
            document.querySelectorAll('.youtube-vod-pick').forEach(function (box) { box.checked = target.checked; });
        }
        if (target.id === 'youtube-vod-select-all' || (target.classList && target.classList.contains('youtube-vod-pick'))) syncCopyBtn();
    });
    document.addEventListener('click', function (event) {
        var delBtn = event.target && event.target.closest('[data-delete-file]');
        if (delBtn && !delBtn.disabled) {
            var delName = delBtn.getAttribute('data-delete-file') || '';
            var delTitle = delBtn.getAttribute('data-delete-title') || delName;
            if (!delName || typeof Swal === 'undefined') return;
            Swal.fire({
                title: I18N.deleteTitle,
                text: I18N.deleteText.replace('%s', delTitle),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: I18N.deleteConfirm,
                cancelButtonText: I18N.deleteCancel,
                background: '#333',
                color: '#fff'
            }).then(function (result) {
                if (!result.isConfirmed) return;
                delBtn.disabled = true;
                fetch('streaming.php?delete=1&file=' + encodeURIComponent(delName), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json().then(function (body) { return { ok: r.ok, body: body }; }); })
                    .then(function (result) {
                        if (!result.ok || !result.body || !result.body.ok) {
                            var err = (result.body && result.body.error) ? result.body.error : I18N.deleteFailed;
                            throw new Error(err);
                        }
                        poll();
                    })
                    .catch(function (err) {
                        delBtn.disabled = false;
                        Swal.fire({ icon: 'error', title: I18N.deleteFailed, text: (err && err.message) ? err.message : I18N.deleteFailed, background: '#333', color: '#fff' });
                    });
            });
            return;
        }
        var ext = event.target && event.target.closest('[data-extend-file]');
        if (ext && !ext.disabled) {
            var fileName = ext.getAttribute('data-extend-file');
            ext.disabled = true;
            fetch('streaming.php?extend=1&file=' + encodeURIComponent(fileName), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json().then(function (body) { return { ok: r.ok, body: body }; }); })
                .then(function (result) {
                    if (!result.ok || !result.body || !result.body.ok) {
                        ext.disabled = false;
                        showToast((result.body && result.body.error) || I18N.extendFailed, 'danger');
                        return;
                    }
                    poll();
                })
                .catch(function () { ext.disabled = false; showToast(I18N.extendFailed, 'danger'); });
            return;
        }
        if (event.target && event.target.closest('#youtube-vod-copy-links')) {
            event.preventDefault();
            var items = [];
            document.querySelectorAll('.youtube-vod-pick:checked').forEach(function (box) {
                items.push({ url: box.getAttribute('data-vod-url') || '', title: box.getAttribute('data-vod-title') || '', name: box.getAttribute('data-vod-name') || '' });
            });
            if (!items.length) { setNotice(I18N.linksNone, 'warning'); return; }
            var modal = document.getElementById('youtube-vod-links-modal');
            var box = document.getElementById('youtube-vod-links-box');
            if (modal && box) {
                box.value = uniqueNamedUrls(items).join('\n');
                modal.classList.add('is-active');
                box.select();
            }
            return;
        }
        if (event.target && (event.target.id === 'youtube-vod-links-close' || event.target.id === 'youtube-vod-links-modal')) {
            var modalClose = document.getElementById('youtube-vod-links-modal');
            if (modalClose) modalClose.classList.remove('is-active');
        }
        if (event.target && event.target.id === 'youtube-vod-links-copy') {
            var area = document.getElementById('youtube-vod-links-box');
            if (area && navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(area.value).then(function () { setNotice(I18N.linksCopied, 'success'); });
            }
        }
        var refresh = event.target && event.target.closest('#refresh-remote-files-btn');
        if (refresh) { event.preventDefault(); poll(); }
        var toggle = event.target && event.target.closest('[data-toggle-password]');
        if (toggle) {
            var inp = document.getElementById(toggle.getAttribute('data-toggle-password'));
            if (inp) {
                inp.type = inp.type === 'password' ? 'text' : 'password';
                var icon = toggle.querySelector('i');
                if (icon) icon.className = inp.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
            }
        }
        var copyBtn = event.target && event.target.closest('[data-copy-target]');
        if (copyBtn) {
            var el = document.getElementById(copyBtn.getAttribute('data-copy-target'));
            if (el && navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(el.value || '');
        }
        if (event.target && (event.target.id === 'toggle-specter-key' || event.target.closest('#toggle-specter-key'))) {
            var keyInp = document.getElementById('specter-stream-key');
            var tbtn = document.getElementById('toggle-specter-key');
            if (keyInp && tbtn) {
                keyInp.type = keyInp.type === 'password' ? 'text' : 'password';
                var ic = tbtn.querySelector('i');
                if (ic) ic.className = keyInp.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
            }
        }
        if (event.target && (event.target.id === 'toggle-twitch-key' || event.target.closest('#toggle-twitch-key'))) {
            var tkey = document.getElementById('twitch_key');
            var tb = document.getElementById('toggle-twitch-key');
            if (tkey && tb) {
                tkey.type = tkey.type === 'password' ? 'text' : 'password';
                var ic2 = tb.querySelector('i');
                if (ic2) ic2.className = tkey.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
            }
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            var modal = document.getElementById('youtube-vod-links-modal');
            if (modal) modal.classList.remove('is-active');
        }
    });
    setInterval(tickCountdowns, 1000);
    tickCountdowns();
    renderUploadBars();
    updateImportYoutubeCells();
    poll();
    setInterval(poll, 5000);
    syncCopyBtn();
})();
</script>
<?php
$content = ob_get_clean();
include 'layout.php';
