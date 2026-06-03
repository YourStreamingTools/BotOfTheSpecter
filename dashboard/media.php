<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
ini_set('max_execution_time', 300);

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('media_page_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
require_once __DIR__ . '/includes/upload_helpers.php';
session_write_close();
$stmt = $db->prepare("SELECT timezone, media_migrated FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$media_migrated = (bool)($channelData['media_migrated'] ?? false);
$stmt->close();
date_default_timezone_set($timezone);

$status = '';

$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

if (($_GET['action'] ?? '') === 'helix_lookup_user' && !empty($_GET['login'])) {
    header('Content-Type: application/json');
    $login = strtolower(preg_replace('/[^a-z0-9_]/i', '', trim($_GET['login'])));
    if ($login === '') {
        echo json_encode(['success' => false, 'error' => t('media_err_invalid_login')]);
        exit;
    }
    $botClientId = '';
    $botOauth    = '';
    $bconn = new mysqli($db_servername, $db_username, $db_password, 'website');
    if (!$bconn->connect_error) {
        $bres = $bconn->query("SELECT * FROM bot_chat_token ORDER BY id ASC LIMIT 1");
        if ($bres && ($brow = $bres->fetch_assoc())) {
            foreach (['twitch_client_id', 'client_id', 'clientID'] as $k) {
                if (!empty($brow[$k])) { $botClientId = trim($brow[$k]); break; }
            }
            foreach (['twitch_oauth_api_token', 'oauth', 'chat_oauth_token', 'twitch_oauth_token', 'twitch_access_token', 'bot_oauth_token'] as $k) {
                if (!empty($brow[$k])) { $botOauth = trim($brow[$k]); break; }
            }
        }
        $bconn->close();
    }
    if ($botClientId === '' || $botOauth === '') {
        echo json_encode(['success' => false, 'error' => t('media_err_bot_credentials')]);
        exit;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer $botOauth\r\nClient-Id: $botClientId\r\n",
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents('https://api.twitch.tv/helix/users?login=' . urlencode($login), false, $ctx);
    if (!$raw) {
        echo json_encode(['success' => false, 'error' => t('media_err_helix_failed')]);
        exit;
    }
    $data = json_decode($raw, true);
    if (empty($data['data'][0]['id'])) {
        echo json_encode(['success' => false, 'error' => t('media_err_user_not_found')]);
        exit;
    }
    echo json_encode([
        'success'   => true,
        'user_id'   => $data['data'][0]['id'],
        'user_name' => $data['data'][0]['login'],
    ]);
    exit;
}

$soundAlertMappings = [];  // file => [reward_id, ...]
if ($r = $db->query("SELECT reward_id, sound_mapping FROM sound_alerts")) {
    while ($row = $r->fetch_assoc()) {
        if ($row['sound_mapping']) {
            $soundAlertMappings[$row['sound_mapping']][] = $row['reward_id'];
        }
    }
    $r->free();
}

$videoAlertMappings = [];  // file => [reward_id, ...]
if ($r = $db->query("SELECT reward_id, video_mapping FROM video_alerts")) {
    while ($row = $r->fetch_assoc()) {
        if ($row['video_mapping']) {
            $videoAlertMappings[$row['video_mapping']][] = $row['reward_id'];
        }
    }
    $r->free();
}

$twitchSoundAlertMappings = [];  // file => [twitch_alert_id, ...]
if ($r = $db->query("SELECT sound_mapping, twitch_alert_id FROM twitch_sound_alerts")) {
    while ($row = $r->fetch_assoc()) {
        if ($row['sound_mapping']) {
            $twitchSoundAlertMappings[$row['sound_mapping']][] = $row['twitch_alert_id'];
        }
    }
    $r->free();
}

$walkonsByFile = [];  // file => [{user_id, user_name, mode}, ...]
if ($r = $db->query("SELECT twitch_user_id, twitch_user_name, media_file, mode FROM walkons")) {
    while ($row = $r->fetch_assoc()) {
        $walkonsByFile[$row['media_file']][] = [
            'user_id'   => $row['twitch_user_id'],
            'user_name' => $row['twitch_user_name'],
            'mode'      => $row['mode'] ?? 'sound',
        ];
    }
    $r->free();
}

// Flatten the reward-id lists for the "is this reward already used somewhere?" check
// used by the modal's add-reward dropdown.
$soundMappedRewards = [];
foreach ($soundAlertMappings as $rids) {
    foreach ($rids as $rid) $soundMappedRewards[] = $rid;
}
$videoMappedRewards = [];
foreach ($videoAlertMappings as $rids) {
    foreach ($rids as $rid) $videoMappedRewards[] = $rid;
}

// Alert builder usage (read-only display only)
$alertMediaFiles = [];
if ($r = $db->query("SELECT id, alert_category, variant_name, alert_image, alert_sound FROM twitch_alerts WHERE alert_image IS NOT NULL OR alert_sound IS NOT NULL")) {
    while ($row = $r->fetch_assoc()) {
        if ($row['alert_image']) {
            $alertMediaFiles[$row['alert_image']][] = ['id' => $row['id'], 'category' => $row['alert_category'], 'variant' => $row['variant_name'], 'type' => 'image'];
        }
        if ($row['alert_sound']) {
            $alertMediaFiles[$row['alert_sound']][] = ['id' => $row['id'], 'category' => $row['alert_category'], 'variant' => $row['variant_name'], 'type' => 'sound'];
        }
    }
    $r->free();
}

// Seen-users cache for the walkon picker typeahead
$seenUsers = [];
if ($r = $db->query("SELECT DISTINCT username FROM seen_users WHERE username IS NOT NULL ORDER BY username ASC")) {
    while ($row = $r->fetch_assoc()) {
        $seenUsers[] = $row['username'];
    }
    $r->free();
}

// Create reward_id => reward_title lookup
$rewardIdToTitle = [];
foreach ($channelPointRewards as $reward) {
    $rewardIdToTitle[$reward['reward_id']] = $reward['reward_title'];
}

// Available twitch events (kept inline — same list the old UI used)
$allTwitchEvents = ['Follow', 'Raid', 'Cheer', 'Subscription', 'Gift Subscription', 'Hype Train Start', 'Hype Train End'];

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

function ajax_respond_mappings($file, $db, $isAjax) {
    if (!$isAjax) return;
    header('Content-Type: application/json');
    $resp = ['success' => true, 'file' => $file, 'mappings' => ['rewards' => [], 'video_rewards' => [], 'events' => [], 'walkons' => []]];
    if ($stmt = $db->prepare("SELECT reward_id FROM sound_alerts WHERE sound_mapping = ?")) {
        $stmt->bind_param('s', $file); $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $resp['mappings']['rewards'][] = $r['reward_id'];
        $stmt->close();
    }
    if ($stmt = $db->prepare("SELECT reward_id FROM video_alerts WHERE video_mapping = ?")) {
        $stmt->bind_param('s', $file); $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $resp['mappings']['video_rewards'][] = $r['reward_id'];
        $stmt->close();
    }
    if ($stmt = $db->prepare("SELECT twitch_alert_id FROM twitch_sound_alerts WHERE sound_mapping = ?")) {
        $stmt->bind_param('s', $file); $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $resp['mappings']['events'][] = $r['twitch_alert_id'];
        $stmt->close();
    }
    if ($stmt = $db->prepare("SELECT twitch_user_id, twitch_user_name, mode FROM walkons WHERE media_file = ?")) {
        $stmt->bind_param('s', $file); $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $resp['mappings']['walkons'][] = ['user_id' => $r['twitch_user_id'], 'user_name' => $r['twitch_user_name'], 'mode' => $r['mode'] ?? 'sound'];
        }
        $stmt->close();
    }
    echo json_encode($resp);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mediaType = $_POST['media_type'] ?? '';
    $action    = $_POST['action'] ?? '';
    // Sound alert (channel point reward) — add / remove
    if ($mediaType === 'sound_alert_mapping') {
        $file     = $_POST['sound_file'] ?? '';
        $rewardId = $_POST['reward_id']  ?? '';
        if ($action === 'add' && $file !== '' && $rewardId !== '') {
            $stmt = $db->prepare("INSERT INTO sound_alerts (reward_id, sound_mapping) VALUES (?, ?)
                                  ON DUPLICATE KEY UPDATE sound_mapping = VALUES(sound_mapping)");
            $stmt->bind_param('ss', $rewardId, $file);
            $stmt->execute(); $stmt->close();
            $status .= t('media_status_sound_added') . "<br>";
            ajax_respond_mappings($file, $db, $isAjax);
        } elseif ($action === 'remove' && $rewardId !== '') {
            $stmt = $db->prepare("DELETE FROM sound_alerts WHERE reward_id = ?");
            $stmt->bind_param('s', $rewardId);
            $stmt->execute(); $stmt->close();
            $status .= t('media_status_sound_removed') . "<br>";
            ajax_respond_mappings($file, $db, $isAjax);
        }
    }
    // Video alert (channel point reward) — add / remove
    if ($mediaType === 'video_alert_mapping') {
        $file     = $_POST['video_file'] ?? '';
        $rewardId = $_POST['reward_id']  ?? '';
        if ($action === 'add' && $file !== '' && $rewardId !== '') {
            $stmt = $db->prepare("INSERT INTO video_alerts (reward_id, video_mapping) VALUES (?, ?)
                                  ON DUPLICATE KEY UPDATE video_mapping = VALUES(video_mapping)");
            $stmt->bind_param('ss', $rewardId, $file);
            $stmt->execute(); $stmt->close();
            $status .= t('media_status_video_added') . "<br>";
            ajax_respond_mappings($file, $db, $isAjax);
        } elseif ($action === 'remove' && $rewardId !== '') {
            $stmt = $db->prepare("DELETE FROM video_alerts WHERE reward_id = ?");
            $stmt->bind_param('s', $rewardId);
            $stmt->execute(); $stmt->close();
            $status .= t('media_status_video_removed') . "<br>";
            ajax_respond_mappings($file, $db, $isAjax);
        }
    }
    // Twitch event — add / remove
    if ($mediaType === 'twitch_event_mapping') {
        $file    = $_POST['sound_file']      ?? '';
        $eventId = $_POST['twitch_alert_id'] ?? '';
        if ($action === 'add' && $file !== '' && $eventId !== '') {
            $stmt = $db->prepare("INSERT INTO twitch_sound_alerts (twitch_alert_id, sound_mapping) VALUES (?, ?)
                                  ON DUPLICATE KEY UPDATE sound_mapping = VALUES(sound_mapping)");
            $stmt->bind_param('ss', $eventId, $file);
            $stmt->execute(); $stmt->close();
            $status .= t('media_status_event_added') . "<br>";
            ajax_respond_mappings($file, $db, $isAjax);
        } elseif ($action === 'remove' && $eventId !== '') {
            $stmt = $db->prepare("DELETE FROM twitch_sound_alerts WHERE twitch_alert_id = ?");
            $stmt->bind_param('s', $eventId);
            $stmt->execute(); $stmt->close();
            $status .= t('media_status_event_removed') . "<br>";
            ajax_respond_mappings($file, $db, $isAjax);
        }
    }
    if ($mediaType === 'walkon_mapping') {
        $file     = $_POST['media_file']       ?? '';
        $userId   = $_POST['twitch_user_id']   ?? '';
        $userName = $_POST['twitch_user_name'] ?? '';
        $walkonMode = $_POST['mode'] ?? 'sound';
        if (!in_array($walkonMode, ['sound', 'sound_overlay', 'video'], true)) $walkonMode = 'sound';
        if ($action === 'add' && $file !== '' && $userId !== '' && $userName !== '') {
            $stmt = $db->prepare("INSERT INTO walkons (twitch_user_id, twitch_user_name, media_file, mode) VALUES (?, ?, ?, ?)
                                  ON DUPLICATE KEY UPDATE media_file = VALUES(media_file), twitch_user_name = VALUES(twitch_user_name), mode = VALUES(mode)");
            $stmt->bind_param('ssss', $userId, $userName, $file, $walkonMode);
            $stmt->execute(); $stmt->close();
            $status .= t('media_status_walkon_added') . "<br>";
            ajax_respond_mappings($file, $db, $isAjax);
        } elseif ($action === 'remove' && $userId !== '') {
            $stmt = $db->prepare("DELETE FROM walkons WHERE twitch_user_id = ?");
            $stmt->bind_param('s', $userId);
            $stmt->execute(); $stmt->close();
            $status .= t('media_status_walkon_removed') . "<br>";
            ajax_respond_mappings($file, $db, $isAjax);
        }
    }
    if (isset($_FILES["filesToUpload"])) {
        $remaining_storage = $max_storage_size - $current_storage_used;
        $uploadStatus = "";
        $uploadHadError = false;
        $targetDir = $media_path;
        $allowedExts = ['mp3', 'mp4', 'png', 'jpg', 'jpeg', 'gif', 'webm'];
        $extLabel = t('media_upload_ext_label');
        if ($targetDir) {
            foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
                if (empty($tmp_name)) continue;
                $fileName = $_FILES["filesToUpload"]["name"][$key];
                $fileSize = $_FILES["filesToUpload"]["size"][$key];
                $fileError = $_FILES["filesToUpload"]["error"][$key] ?? 0;
                $displayName = htmlspecialchars(basename($fileName));
                if ($fileError !== UPLOAD_ERR_OK) { $uploadStatus .= t('media_upload_error', [$displayName]) . "<br>"; $uploadHadError = true; continue; }
                if (!is_uploaded_file($tmp_name)) { $uploadStatus .= t('media_upload_failed_invalid', [$displayName]) . "<br>"; $uploadHadError = true; continue; }
                if ($current_storage_used + $fileSize > $max_storage_size) {
                    $uploadStatus .= t('media_upload_failed_storage', [$displayName]) . "<br>"; $uploadHadError = true; continue;
                }
                $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (!in_array($fileType, $allowedExts, true)) {
                    $uploadStatus .= t('media_upload_failed_type', ['name' => $displayName, 'types' => $extLabel]) . "<br>"; $uploadHadError = true; continue;
                }
                if (!upload_validate_extension_and_mime($tmp_name, $fileType, $allowedExts)) {
                    $uploadStatus .= t('media_upload_failed_mime', [$displayName]) . "<br>"; $uploadHadError = true; continue;
                }
                $safeName = upload_sanitize_filename($fileName, $fileType);
                $target = upload_unique_target($targetDir, $safeName);
                if (move_uploaded_file($tmp_name, $target['path'])) {
                    $current_storage_used += $fileSize;
                    $uploadStatus .= t('media_upload_success', [htmlspecialchars($target['name'])]) . "<br>";
                } else {
                    $uploadStatus .= t('media_upload_failed_generic', [$displayName]) . "<br>";
                    $uploadHadError = true;
                }
            }
            $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
            $status .= $uploadStatus;
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => $status,
                'success' => !$uploadHadError,
                'storage_used' => $current_storage_used,
                'max_storage' => $max_storage_size,
                'storage_percentage' => $storage_percentage,
            ]);
            exit;
        }
    }
    // File deletion — safety-gated. A file that's still linked to a reward,
    // event, walkon, or alert variant is refused; the user must unlink it
    // first so an accidental delete can't silently break their overlays.
    if (isset($_POST['delete_files']) && is_array($_POST['delete_files'])) {
        $deleteStatus = "";
        $db->begin_transaction();
        foreach ($_POST['delete_files'] as $file_to_delete) {
            $filename = basename($file_to_delete);
            $full_path = $media_path . '/' . $filename;
            if (!is_file($full_path)) {
                $deleteStatus .= t('media_delete_failed', [htmlspecialchars($filename)]) . "<br>";
                continue;
            }
            // Count every place this file is still referenced
            $refParts = [];
            $checks = [
                ['sound_alerts',        'sound_mapping',  'media_ref_reward',  'media_ref_reward_plural'],
                ['video_alerts',        'video_mapping',  'media_ref_video',   'media_ref_video_plural'],
                ['twitch_sound_alerts', 'sound_mapping',  'media_ref_event',   'media_ref_event_plural'],
                ['walkons',             'media_file',     'media_ref_walkon',  'media_ref_walkon_plural'],
            ];
            foreach ($checks as $c) {
                $cstmt = $db->prepare("SELECT COUNT(*) AS n FROM {$c[0]} WHERE {$c[1]} = ?");
                if (!$cstmt) continue;
                $cstmt->bind_param('s', $filename);
                $cstmt->execute();
                $n = (int)$cstmt->get_result()->fetch_assoc()['n'];
                $cstmt->close();
                if ($n > 0) $refParts[] = t($n === 1 ? $c[2] : $c[3], [$n]);
            }
            // Alert builder uses two columns on twitch_alerts; sum them
            $abstmt = $db->prepare("SELECT (SELECT COUNT(*) FROM twitch_alerts WHERE alert_image = ?) + (SELECT COUNT(*) FROM twitch_alerts WHERE alert_sound = ?) AS n");
            if ($abstmt) {
                $abstmt->bind_param('ss', $filename, $filename);
                $abstmt->execute();
                $abn = (int)$abstmt->get_result()->fetch_assoc()['n'];
                $abstmt->close();
                if ($abn > 0) $refParts[] = t($abn === 1 ? 'media_ref_alert' : 'media_ref_alert_plural', [$abn]);
            }
            if (!empty($refParts)) {
                $deleteStatus .= t('media_delete_linked', ['name' => htmlspecialchars($filename), 'refs' => implode(', ', $refParts)]) . "<br>";
                continue;
            }
            if (unlink($full_path)) {
                $deleteStatus .= t('media_delete_success', [htmlspecialchars($filename)]) . "<br>";
            } else {
                $deleteStatus .= t('media_delete_failed', [htmlspecialchars($filename)]) . "<br>";
            }
        }
        $db->commit();
        $current_storage_used = calculateStorageUsed([$media_path, $walkon_path, $videoalert_path, $user_music_path]);
        $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
        $status .= $deleteStatus;
    }
}

// All files come from the unified media library
$all_media_files = is_dir($media_path) ? array_values(array_diff(scandir($media_path), array('.', '..'))) : [];
sort($all_media_files, SORT_STRING | SORT_FLAG_CASE);

function media_file_type($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'mp3') return 'audio';
    if ($ext === 'mp4') return 'video';
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webm'], true)) return 'image';
    return 'other';
}
function media_file_size($path) {
    return is_file($path) ? filesize($path) : 0;
}
function format_bytes($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

ob_start();
?>
<!-- Storage Usage (shared across all tabs) -->
<div class="sp-alert sp-alert-info media-storage-bar">
    <div class="media-storage-header">
        <span><i class="fas fa-database"></i> <strong><?php echo t('alerts_storage_usage'); ?>:</strong></span>
        <span><?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB / <?php echo round($max_storage_size / 1024 / 1024, 2); ?>MB (<?php echo round($storage_percentage, 2); ?>%)</span>
    </div>
    <progress class="progress" value="<?php echo $storage_percentage; ?>" max="100"></progress>
</div>
<?php if (!empty($status)): ?>
    <div class="sp-alert sp-alert-info sp-notif media-notif">
        <?php echo $status; ?>
    </div>
<?php endif; ?>
<!-- Unified Upload Card -->
<div class="sp-card media-upload-card">
    <header class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-upload"></i> <?= t('media_upload_media') ?></span>
    </header>
    <div class="sp-card-body">
        <form action="" method="POST" enctype="multipart/form-data" class="media-upload-form" id="unified-upload-form">
            <input type="hidden" name="media_type" value="media_upload">
            <div class="sp-form-group">
                <small class="media-upload-hint"><i class="fas fa-info-circle"></i> <?= t('media_upload_hint') ?></small>
            </div>
            <div class="sp-form-group">
                <label class="media-drop-zone" id="unified-drop-zone">
                    <i class="fas fa-cloud-upload-alt media-drop-zone-icon"></i>
                    <span class="file-list-label"><?= t('media_no_files_selected') ?></span>
                    <div class="media-drop-zone-hint"><?= t('media_click_or_drag') ?></div>
                    <input type="file" name="filesToUpload[]" id="unified-file-input" multiple accept=".mp3,.mp4,.png,.jpg,.jpeg,.gif,.webm" hidden>
                </label>
            </div>
            <div class="upload-status-container media-upload-status">
                <div class="sp-alert sp-alert-info">
                    <div class="media-upload-progress-header">
                        <strong class="upload-status-text"><?= t('media_preparing_upload') ?></strong>
                        <span class="upload-progress-percent media-upload-progress-percent">0%</span>
                    </div>
                    <progress class="upload-progress media-upload-progress" value="0" max="100">0%</progress>
                </div>
            </div>
            <button class="sp-btn sp-btn-primary upload-btn media-upload-btn" type="submit">
                <i class="fas fa-upload"></i>
                <span class="upload-btn-text"><?= t('media_upload_media') ?></span>
            </button>
        </form>
    </div>
</div>
<?php if (!$media_migrated): ?>
<!-- Migrate to unified media library -->
<div class="sp-card media-migrate-card">
    <header class="sp-card-header media-migrate-header">
        <span class="sp-card-title"><i class="fas fa-arrow-circle-up"></i> <?= t('media_migrate_title') ?></span>
    </header>
    <div class="sp-card-body media-migrate-body">
        <div class="sp-alert sp-alert-warning media-migrate-warning">
            <p><i class="fas fa-exclamation-triangle"></i> <strong><?= t('media_migrate_important') ?></strong></p>
            <p><?= t('media_migrate_warning_body') ?></p>
            <p><?= t('media_migrate_two_options') ?></p>
            <ul>
                <li><?= t('media_migrate_option_now') ?></li>
                <li><?= t('media_migrate_option_hold') ?></li>
            </ul>
        </div>
        <h4><?= t('media_how_it_works_title') ?></h4>
        <p><?= t('media_how_it_works_body') ?></p>
        <ul>
            <li><?= t('media_benefit_one_upload') ?></li>
            <li><?= t('media_benefit_unified') ?></li>
            <li><?= t('media_benefit_non_destructive') ?></li>
            <li><?= t('media_benefit_walkons_autolinked') ?></li>
        </ul>
        <button type="button" id="media-migrate-btn" class="sp-btn sp-btn-warning">
            <i class="fas fa-arrow-right"></i> <?= t('media_migrate_btn') ?>
        </button>
    </div>
</div>
<?php else: ?>
<div class="sp-alert sp-alert-success media-migrated-notice">
    <i class="fas fa-check-circle"></i> <?= t('media_using_unified_notice') ?>
</div>
<?php endif; ?>
<!-- Filter + search bar -->
<div class="media-filter-bar">
    <button type="button" class="sp-btn sp-btn-ghost media-filter-btn is-active" data-filter="all"><?= t('media_filter_all') ?></button>
    <button type="button" class="sp-btn sp-btn-ghost media-filter-btn" data-filter="rewards"><?= t('media_filter_with_rewards') ?></button>
    <button type="button" class="sp-btn sp-btn-ghost media-filter-btn" data-filter="events"><?= t('media_filter_with_events') ?></button>
    <button type="button" class="sp-btn sp-btn-ghost media-filter-btn" data-filter="walkons"><?= t('media_filter_walkons') ?></button>
    <button type="button" class="sp-btn sp-btn-ghost media-filter-btn" data-filter="unused"><?= t('media_filter_unused') ?></button>
    <button type="button" class="sp-btn sp-btn-ghost media-filter-btn" data-filter="videos"><?= t('media_filter_videos') ?></button>
    <input type="search" class="sp-input media-search-input" id="media-search-input" placeholder="<?= htmlspecialchars(t('media_search_placeholder')) ?>">
</div>
<!-- Files list -->
<div class="sp-card media-files-card">
    <header class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-folder-open"></i> <?= t('media_library_title') ?></span>
        <button class="sp-btn sp-btn-danger media-delete-selected-btn" disabled>
            <i class="fas fa-trash"></i> <span><?= t('media_delete_selected') ?></span>
        </button>
    </header>
    <div class="sp-card-body">
        <?php if (empty($all_media_files)): ?>
            <div class="media-empty-state">
                <p><?= t('media_empty_state') ?></p>
            </div>
        <?php else: ?>
        <form action="" method="POST" id="mediaDeleteForm" class="media-delete-form">
            <ul class="media-file-list" id="media-file-list">
                <?php foreach ($all_media_files as $file):
                    if (!is_file($media_path . '/' . $file)) continue;
                    $size = media_file_size($media_path . '/' . $file);
                    $type = media_file_type($file);
                    $rewardCount = count($soundAlertMappings[$file] ?? []) + count($videoAlertMappings[$file] ?? []);
                    $eventCount  = count($twitchSoundAlertMappings[$file] ?? []);
                    $walkonCount = count($walkonsByFile[$file] ?? []);
                    $alertCount  = count($alertMediaFiles[$file] ?? []);
                    $totalCount  = $rewardCount + $eventCount + $walkonCount + $alertCount;
                    $summaryParts = [];
                    if ($rewardCount > 0) $summaryParts[] = t($rewardCount === 1 ? 'media_summary_reward' : 'media_summary_reward_plural', [$rewardCount]);
                    if ($eventCount  > 0) $summaryParts[] = t($eventCount === 1 ? 'media_summary_event' : 'media_summary_event_plural', [$eventCount]);
                    if ($walkonCount > 0) $summaryParts[] = t($walkonCount === 1 ? 'media_summary_walkon' : 'media_summary_walkon_plural', [$walkonCount]);
                    if ($alertCount  > 0) $summaryParts[] = t($alertCount === 1 ? 'media_summary_alert' : 'media_summary_alert_plural', [$alertCount]);
                    $summary = empty($summaryParts) ? t('media_summary_unused') : implode(' · ', $summaryParts);
                ?>
                <li class="media-file-row"
                    data-file="<?php echo htmlspecialchars($file); ?>"
                    data-type="<?php echo htmlspecialchars($type); ?>"
                    data-has-rewards="<?php echo $rewardCount > 0 ? '1' : '0'; ?>"
                    data-has-events="<?php echo $eventCount > 0 ? '1' : '0'; ?>"
                    data-has-walkons="<?php echo $walkonCount > 0 ? '1' : '0'; ?>"
                    data-has-alerts="<?php echo $alertCount > 0 ? '1' : '0'; ?>"
                    data-alert-count="<?php echo $alertCount; ?>"
                    data-total="<?php echo $totalCount; ?>">
                    <input type="checkbox" class="media-file-check" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>">
                    <button type="button" class="media-file-name" data-file="<?php echo htmlspecialchars($file); ?>">
                        <?php echo htmlspecialchars($file); ?>
                    </button>
                    <span class="media-file-meta"><?php echo strtoupper(pathinfo($file, PATHINFO_EXTENSION)); ?> · <?php echo format_bytes($size); ?></span>
                    <span class="media-file-summary<?php echo $totalCount === 0 ? ' is-unused' : ''; ?>"><?php echo htmlspecialchars($summary); ?></span>
                    <?php if ($type === 'audio' || $type === 'video'): ?>
                    <button type="button" class="sp-btn sp-btn-primary sp-btn-sm media-test-btn" data-file="<?php echo htmlspecialchars($file); ?>" data-type="<?php echo $type; ?>" title="<?php echo htmlspecialchars(t('media_test_playback')); ?>">
                        <i class="fas fa-play"></i>
                    </button>
                    <?php else: ?>
                    <span class="sp-btn sp-btn-sm media-test-btn-placeholder" title="<?php echo htmlspecialchars(t('media_images_managed_via_builder')); ?>">&nbsp;</span>
                    <?php endif; ?>
                    <?php if ($totalCount > 0): ?>
                    <button type="button" class="sp-btn sp-btn-sm media-delete-locked" data-file="<?php echo htmlspecialchars($file); ?>" data-summary="<?php echo htmlspecialchars($summary); ?>" title="<?php echo htmlspecialchars(t('media_in_use_title')); ?>">
                        <i class="fas fa-lock"></i>
                    </button>
                    <?php else: ?>
                    <button type="button" class="sp-btn sp-btn-danger sp-btn-sm media-delete-single" data-file="<?php echo htmlspecialchars($file); ?>" title="<?php echo htmlspecialchars(t('media_delete_file')); ?>">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="media-empty-filter" style="display:none;">
                <p><?= t('media_no_files_match_filter') ?></p>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Per-file modal -->
<div class="sp-modal-backdrop" id="media-modal-backdrop" style="display:none;">
    <div class="sp-modal media-modal" role="dialog" aria-modal="true" aria-labelledby="media-modal-title">
        <header class="sp-modal-head">
            <span class="sp-modal-title" id="media-modal-title">…</span>
            <button type="button" class="sp-modal-close" id="media-modal-close" aria-label="<?= htmlspecialchars(t('media_modal_close')) ?>">&times;</button>
        </header>
        <div class="sp-modal-body" id="media-modal-body">
            <!-- populated by JS -->
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();

// Ship all mapping data to the client so the modal can render without
// another round trip on open.
$mediaDataJson = json_encode([
    'sound_alerts'        => $soundAlertMappings,
    'video_alerts'        => $videoAlertMappings,
    'twitch_events'       => $twitchSoundAlertMappings,
    'walkons'             => $walkonsByFile,
    'alert_builder'       => $alertMediaFiles,
    'rewards'             => array_values($channelPointRewards),
    'reward_titles'       => $rewardIdToTitle,
    'reward_used_sound'   => array_values(array_unique($soundMappedRewards)),
    'reward_used_video'   => array_values(array_unique($videoMappedRewards)),
    'twitch_events_list'  => $allTwitchEvents,
    'seen_users'          => $seenUsers,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

ob_start();
?>
<script>
window.__MEDIA_DATA = <?php echo $mediaDataJson; ?>;
window.__MEDIA_CTX  = {
    apiKey:   <?php echo json_encode($api_key); ?>,
    channel:  <?php echo json_encode($username); ?>
};
window.__MEDIA_I18N = {
    unknown_reward:        <?php echo json_encode(t('media_js_unknown_reward')); ?>,
    no_mappings:           <?php echo json_encode(t('media_js_no_mappings')); ?>,
    used_by_alert_builder: <?php echo json_encode(t('media_js_used_by_alert_builder')); ?>,
    alert_not_attached:    <?php echo json_encode(t('media_js_alert_not_attached')); ?>,
    header_video:          <?php echo json_encode(t('media_js_header_video')); ?>,
    header_audio:          <?php echo json_encode(t('media_js_header_audio')); ?>,
    header_image:          <?php echo json_encode(t('media_js_header_image')); ?>,
    header_file:           <?php echo json_encode(t('media_js_header_file')); ?>,
    section_rewards:       <?php echo json_encode(t('media_js_section_rewards')); ?>,
    section_rewards_video: <?php echo json_encode(t('media_js_section_rewards_video')); ?>,
    section_events:        <?php echo json_encode(t('media_js_section_events')); ?>,
    section_walkons:       <?php echo json_encode(t('media_js_section_walkons')); ?>,
    add_reward:            <?php echo json_encode(t('media_js_add_reward')); ?>,
    add_event:             <?php echo json_encode(t('media_js_add_event')); ?>,
    walkon_sound_only:     <?php echo json_encode(t('media_js_walkon_sound_only')); ?>,
    walkon_sound_overlay:  <?php echo json_encode(t('media_js_walkon_sound_overlay')); ?>,
    walkon_username_ph:    <?php echo json_encode(t('media_js_walkon_username_ph')); ?>,
    walkon_add_user:       <?php echo json_encode(t('media_js_walkon_add_user')); ?>,
    walkon_tag_picname:    <?php echo json_encode(t('media_js_walkon_tag_picname')); ?>,
    walkon_tag_video:      <?php echo json_encode(t('media_js_walkon_tag_video')); ?>,
    looking_up:            <?php echo json_encode(t('media_js_looking_up')); ?>,
    lookup_failed:         <?php echo json_encode(t('media_js_lookup_failed')); ?>,
    summary_unused:        <?php echo json_encode(t('media_summary_unused')); ?>,
    summary_reward:        <?php echo json_encode(t('media_summary_reward')); ?>,
    summary_reward_plural: <?php echo json_encode(t('media_summary_reward_plural')); ?>,
    summary_event:         <?php echo json_encode(t('media_summary_event')); ?>,
    summary_event_plural:  <?php echo json_encode(t('media_summary_event_plural')); ?>,
    summary_walkon:        <?php echo json_encode(t('media_summary_walkon')); ?>,
    summary_walkon_plural: <?php echo json_encode(t('media_summary_walkon_plural')); ?>,
    summary_alert:         <?php echo json_encode(t('media_summary_alert')); ?>,
    summary_alert_plural:  <?php echo json_encode(t('media_summary_alert_plural')); ?>,
    no_files_selected:     <?php echo json_encode(t('media_no_files_selected')); ?>,
    upload_no_files_title: <?php echo json_encode(t('media_js_upload_no_files_title')); ?>,
    upload_no_files_text:  <?php echo json_encode(t('media_js_upload_no_files_text')); ?>,
    uploading_files:       <?php echo json_encode(t('media_js_uploading_files')); ?>,
    uploading_pct:         <?php echo json_encode(t('media_js_uploading_pct')); ?>,
    processing_files:      <?php echo json_encode(t('media_js_processing_files')); ?>,
    upload_complete:       <?php echo json_encode(t('media_js_upload_complete')); ?>,
    uploading_btn:         <?php echo json_encode(t('media_js_uploading_btn')); ?>,
    upload_failed_title:   <?php echo json_encode(t('media_js_upload_failed_title')); ?>,
    upload_failed_generic: <?php echo json_encode(t('media_js_upload_failed_generic')); ?>,
    upload_failed_retry:   <?php echo json_encode(t('media_js_upload_failed_retry')); ?>,
    upload_btn_label:      <?php echo json_encode(t('media_upload_media')); ?>,
    upload_btn_short:      <?php echo json_encode(t('media_js_upload_btn_short')); ?>,
    bulk_in_use_title:     <?php echo json_encode(t('media_js_bulk_in_use_title')); ?>,
    bulk_in_use_intro:     <?php echo json_encode(t('media_js_bulk_in_use_intro')); ?>,
    bulk_in_use_outro:     <?php echo json_encode(t('media_js_bulk_in_use_outro')); ?>,
    delete_files_title:    <?php echo json_encode(t('media_js_delete_files_title')); ?>,
    delete_files_text:     <?php echo json_encode(t('media_js_delete_files_text')); ?>,
    delete_file_title:     <?php echo json_encode(t('media_js_delete_file_title')); ?>,
    delete_file_text:      <?php echo json_encode(t('media_js_delete_file_text')); ?>,
    confirm_delete:        <?php echo json_encode(t('media_js_confirm_delete')); ?>,
    cancel:                <?php echo json_encode(t('media_js_cancel')); ?>,
    locked_title:          <?php echo json_encode(t('media_js_locked_title')); ?>,
    locked_body:           <?php echo json_encode(t('media_js_locked_body')); ?>,
    migrate_title:         <?php echo json_encode(t('media_js_migrate_title')); ?>,
    migrate_body:          <?php echo json_encode(t('media_js_migrate_body')); ?>,
    migrate_confirm:       <?php echo json_encode(t('media_js_migrate_confirm')); ?>,
    migrating:             <?php echo json_encode(t('media_js_migrating')); ?>,
    migrate_done_title:    <?php echo json_encode(t('media_js_migrate_done_title')); ?>,
    migrate_failed_title:  <?php echo json_encode(t('media_js_migrate_failed_title')); ?>,
    migrate_failed_unknown:<?php echo json_encode(t('media_js_migrate_failed_unknown')); ?>,
    migrate_failed_server: <?php echo json_encode(t('media_js_migrate_failed_server')); ?>,
    migrate_btn_label:     <?php echo json_encode(t('media_migrate_btn')); ?>
};

$(document).ready(function () {
    var data = window.__MEDIA_DATA;
    var I18N = window.__MEDIA_I18N;
    function fmtCount(n, singularKey, pluralKey) {
        var tpl = (n === 1 ? I18N[singularKey] : I18N[pluralKey]) || '%s';
        return tpl.replace('%s', n);
    }
    // ------------------------------------------------------------------
    // Modal rendering
    // ------------------------------------------------------------------
    var modal     = document.getElementById('media-modal-backdrop');
    var modalBody = document.getElementById('media-modal-body');
    var modalTitle= document.getElementById('media-modal-title');
    var activeFile = null;
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function fileType(file) {
        var ext = (file.split('.').pop() || '').toLowerCase();
        if (ext === 'mp4') return 'video';
        if (ext === 'mp3') return 'audio';
        if (['png', 'jpg', 'jpeg', 'gif', 'webm'].indexOf(ext) !== -1) return 'image';
        return 'other';
    }
    function rewardTitle(id) {
        return data.reward_titles[id] || I18N.unknown_reward;
    }
    // Available rewards: not currently mapped to ANY file via sound OR video.
    // (A reward triggers one sound — if it's already used elsewhere, hide it.)
    function availableRewards(currentRewardIds) {
        var used = new Set(data.reward_used_sound.concat(data.reward_used_video));
        // Allow the rewards already attached to THIS file to stay selectable as remove targets
        currentRewardIds.forEach(function (id) { used.delete(id); });
        return data.rewards.filter(function (r) { return !used.has(r.reward_id); });
    }
    function availableEvents(currentEventIds) {
        var used = new Set();
        Object.keys(data.twitch_events).forEach(function (file) {
            (data.twitch_events[file] || []).forEach(function (eid) { used.add(eid); });
        });
        currentEventIds.forEach(function (id) { used.delete(id); });
        return data.twitch_events_list.filter(function (e) { return !used.has(e); });
    }
    function renderSection(title, chips, addControl, readonly) {
        var html = '<div class="media-modal-section' + (readonly ? ' media-modal-section-readonly' : '') + '">';
        html += '<div class="media-modal-section-title">' + escapeHtml(title) + '</div>';
        html += '<div class="mapping-chips">';
        if (chips.length === 0) {
            html += '<em class="mapping-empty">' + escapeHtml(I18N.no_mappings) + '</em>';
        } else {
            chips.forEach(function (c) {
                html += '<span class="mapping-chip">' + escapeHtml(c.label);
                if (!readonly && c.removeData) {
                    html += '<button type="button" class="mapping-chip-remove"';
                    Object.keys(c.removeData).forEach(function (k) {
                        html += ' data-' + k + '="' + escapeHtml(c.removeData[k]) + '"';
                    });
                    html += '>&times;</button>';
                }
                html += '</span>';
            });
        }
        html += '</div>';
        if (addControl) html += addControl;
        html += '</div>';
        return html;
    }
    function renderModal(file) {
        activeFile = file;
        modalTitle.textContent = file;
        var type = fileType(file);
        var ext  = (file.split('.').pop() || '').toLowerCase();
        var alertBuilder = data.alert_builder[file] || [];
        var html = '';
        // Header
        var headerLabel = type === 'video' ? I18N.header_video
                        : type === 'audio' ? I18N.header_audio
                        : type === 'image' ? I18N.header_image.replace('%s', ext.toUpperCase())
                        : I18N.header_file;
        html += '<div class="media-modal-fileinfo">' + escapeHtml(headerLabel) + '</div>';
        // Image files are alert-builder territory only — no channel-points,
        // events or walkons. Render just the read-only usage chips.
        if (type === 'image') {
            if (alertBuilder.length === 0) {
                html += '<div class="media-modal-section media-modal-section-readonly">'
                     +    '<div class="media-modal-section-title">' + escapeHtml(I18N.used_by_alert_builder) + '</div>'
                     +    '<div class="mapping-chips"><em class="mapping-empty">' + I18N.alert_not_attached + '</em></div>'
                     +  '</div>';
            } else {
                var abChips = alertBuilder.map(function (a) {
                    return { label: a.category + ' · ' + a.variant + ' (' + a.type + ')' };
                });
                html += renderSection(I18N.used_by_alert_builder, abChips, '', true);
            }
            modalBody.innerHTML = html;
            return;
        }
        var rewards     = (type === 'video' ? data.video_alerts[file] : data.sound_alerts[file]) || [];
        var events      = data.twitch_events[file] || [];
        var walkons     = data.walkons[file]       || [];
        // Channel point rewards (sound for audio, video for video)
        var availRewards = availableRewards(rewards);
        var rewardChips = rewards.map(function (rid) {
            return {
                label: rewardTitle(rid),
                removeData: {
                    kind: type === 'video' ? 'video_reward' : 'sound_reward',
                    'reward-id': rid
                }
            };
        });
        var addReward = '';
        if (availRewards.length > 0) {
            addReward = '<select class="sp-select mapping-add-select" data-add-kind="' + (type === 'video' ? 'video_reward' : 'sound_reward') + '">';
            addReward += '<option value="">' + escapeHtml(I18N.add_reward) + '</option>';
            availRewards.forEach(function (r) {
                addReward += '<option value="' + escapeHtml(r.reward_id) + '">' + escapeHtml(r.reward_title) + '</option>';
            });
            addReward += '</select>';
        }
        html += renderSection(type === 'video' ? I18N.section_rewards_video : I18N.section_rewards, rewardChips, addReward, false);
        // Twitch events (audio only — no MP4 events today)
        if (type !== 'video') {
            var availEvents = availableEvents(events);
            var eventChips = events.map(function (eid) {
                return { label: eid, removeData: { kind: 'event', 'event-id': eid } };
            });
            var addEvent = '';
            if (availEvents.length > 0) {
                addEvent = '<select class="sp-select mapping-add-select" data-add-kind="event">';
                addEvent += '<option value="">' + escapeHtml(I18N.add_event) + '</option>';
                availEvents.forEach(function (e) {
                    addEvent += '<option value="' + escapeHtml(e) + '">' + escapeHtml(e) + '</option>';
                });
                addEvent += '</select>';
            }
            html += renderSection(I18N.section_events, eventChips, addEvent, false);
        }
        // Walkons — audio: sound only or sound + picture & name; video: video alert
        if (type === 'audio' || type === 'video') {
            var walkonChips = walkons.map(function (w) {
                var m = w.mode || 'sound';
                var tag = m === 'sound_overlay' ? (' · ' + I18N.walkon_tag_picname) : (m === 'video' ? (' · ' + I18N.walkon_tag_video) : '');
                return { label: '@' + w.user_name + tag, removeData: { kind: 'walkon', 'user-id': w.user_id } };
            });
            var modeSelect = '';
            if (type === 'audio') {
                modeSelect = '<select class="sp-select walkon-mode-select">'
                    + '<option value="sound">' + escapeHtml(I18N.walkon_sound_only) + '</option>'
                    + '<option value="sound_overlay">' + escapeHtml(I18N.walkon_sound_overlay) + '</option>'
                    + '</select>';
            }
            var addWalkon = ''
                + '<div class="walkon-add-wrap">'
                + '  <input type="text" class="sp-input walkon-add-input" placeholder="' + escapeHtml(I18N.walkon_username_ph) + '" autocomplete="off" list="walkon-seen-users">'
                +    modeSelect
                + '  <button type="button" class="sp-btn sp-btn-primary sp-btn-sm walkon-add-confirm">' + escapeHtml(I18N.walkon_add_user) + '</button>'
                + '  <span class="walkon-add-status"></span>'
                + '</div>';
            html += renderSection(I18N.section_walkons, walkonChips, addWalkon, false);
        }
        // Alert builder (read-only)
        if (alertBuilder.length > 0) {
            var abChips = alertBuilder.map(function (a) {
                return { label: a.category + ' · ' + a.variant + ' (' + a.type + ')' };
            });
            html += renderSection(I18N.used_by_alert_builder, abChips, '', true);
        }
        modalBody.innerHTML = html;
    }
    function openModal(file) {
        renderModal(file);
        modal.style.display = 'flex';
    }
    function closeModal() {
        modal.style.display = 'none';
        activeFile = null;
    }
    document.getElementById('media-modal-close').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.style.display !== 'none') closeModal(); });
    // Open the modal when a filename is clicked
    $(document).on('click', '.media-file-name', function () {
        openModal($(this).data('file'));
    });
    function postMapping(payload, onSuccess) {
        $.ajax({
            url: '',
            type: 'POST',
            data: payload,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            dataType: 'json',
            success: function (resp) {
                if (resp && resp.success) {
                    // Refresh local cache for this file
                    var f = resp.file;
                    data.sound_alerts[f]  = resp.mappings.rewards;
                    data.video_alerts[f]  = resp.mappings.video_rewards;
                    data.twitch_events[f] = resp.mappings.events;
                    data.walkons[f]       = resp.mappings.walkons;
                    // Rebuild flattened reward-used sets so the dropdowns reflect availability
                    var sUsed = new Set(); var vUsed = new Set();
                    Object.keys(data.sound_alerts).forEach(function (file) {
                        (data.sound_alerts[file] || []).forEach(function (r) { sUsed.add(r); });
                    });
                    Object.keys(data.video_alerts).forEach(function (file) {
                        (data.video_alerts[file] || []).forEach(function (r) { vUsed.add(r); });
                    });
                    data.reward_used_sound = Array.from(sUsed);
                    data.reward_used_video = Array.from(vUsed);
                    if (activeFile === f) renderModal(f);
                    updateRowSummary(f);
                    if (onSuccess) onSuccess(resp);
                } else if (resp && resp.error) {
                    console.error('Mapping update failed:', resp.error);
                }
            },
            error: function () { console.error('Mapping update request failed'); }
        });
    }
    function updateRowSummary(file) {
        var row = document.querySelector('.media-file-row[data-file="' + CSS.escape(file) + '"]');
        if (!row) return;
        var r = (data.sound_alerts[file] || []).length + (data.video_alerts[file] || []).length;
        var e = (data.twitch_events[file] || []).length;
        var w = (data.walkons[file] || []).length;
        // Alert builder count is managed in alerts.php — keep what the row was rendered with
        var a = parseInt(row.dataset.alertCount || '0', 10);
        var total = r + e + w + a;
        var parts = [];
        if (r > 0) parts.push(fmtCount(r, 'summary_reward', 'summary_reward_plural'));
        if (e > 0) parts.push(fmtCount(e, 'summary_event', 'summary_event_plural'));
        if (w > 0) parts.push(fmtCount(w, 'summary_walkon', 'summary_walkon_plural'));
        if (a > 0) parts.push(fmtCount(a, 'summary_alert', 'summary_alert_plural'));
        var summaryEl = row.querySelector('.media-file-summary');
        summaryEl.textContent = total === 0 ? I18N.summary_unused : parts.join(' · ');
        summaryEl.classList.toggle('is-unused', total === 0);
        row.dataset.hasRewards = r > 0 ? '1' : '0';
        row.dataset.hasEvents  = e > 0 ? '1' : '0';
        row.dataset.hasWalkons = w > 0 ? '1' : '0';
        row.dataset.hasAlerts  = a > 0 ? '1' : '0';
        row.dataset.total = String(total);
        applyFilter();
    }
    // Add mapping (dropdown change)
    $(document).on('change', '.mapping-add-select', function () {
        if (!this.value || !activeFile) return;
        var kind = $(this).data('add-kind');
        var payload = { action: 'add' };
        if (kind === 'sound_reward') {
            payload.media_type = 'sound_alert_mapping';
            payload.sound_file = activeFile;
            payload.reward_id  = this.value;
        } else if (kind === 'video_reward') {
            payload.media_type = 'video_alert_mapping';
            payload.video_file = activeFile;
            payload.reward_id  = this.value;
        } else if (kind === 'event') {
            payload.media_type     = 'twitch_event_mapping';
            payload.sound_file     = activeFile;
            payload.twitch_alert_id= this.value;
        } else { return; }
        postMapping(payload);
    });
    // Remove mapping (× on a chip)
    $(document).on('click', '.mapping-chip-remove', function () {
        if (!activeFile) return;
        var kind = $(this).data('kind');
        var payload = { action: 'remove' };
        if (kind === 'sound_reward') {
            payload.media_type = 'sound_alert_mapping';
            payload.sound_file = activeFile;
            payload.reward_id  = $(this).data('reward-id');
        } else if (kind === 'video_reward') {
            payload.media_type = 'video_alert_mapping';
            payload.video_file = activeFile;
            payload.reward_id  = $(this).data('reward-id');
        } else if (kind === 'event') {
            payload.media_type = 'twitch_event_mapping';
            payload.sound_file = activeFile;
            payload.twitch_alert_id = $(this).data('event-id');
        } else if (kind === 'walkon') {
            payload.media_type = 'walkon_mapping';
            payload.media_file = activeFile;
            payload.twitch_user_id = $(this).data('user-id');
        } else { return; }
        postMapping(payload);
    });
    $(document).on('click', '.walkon-add-confirm', function () {
        if (!activeFile) return;
        var wrap    = $(this).closest('.walkon-add-wrap');
        var input   = wrap.find('.walkon-add-input');
        var status  = wrap.find('.walkon-add-status');
        var modeSel = wrap.find('.walkon-mode-select');
        // Audio files carry a mode select; video files are always 'video'.
        var mode    = modeSel.length ? modeSel.val() : (fileType(activeFile) === 'video' ? 'video' : 'sound');
        var raw     = (input.val() || '').trim().replace(/^@/, '').toLowerCase();
        if (!raw) return;
        status.text(I18N.looking_up.replace('%s', raw));
        // Helix resolves both seen_users and unknown logins to a Twitch user_id.
        // Going through Helix unconditionally keeps the path uniform.
        $.get('', { action: 'helix_lookup_user', login: raw }, function (resp) {
            if (!resp || !resp.success) {
                status.text(resp && resp.error ? resp.error : I18N.lookup_failed);
                return;
            }
            postMapping({
                media_type: 'walkon_mapping',
                action: 'add',
                media_file: activeFile,
                twitch_user_id: resp.user_id,
                twitch_user_name: resp.user_name,
                mode: mode
            }, function () { input.val(''); status.text(''); });
        }, 'json').fail(function () { status.text(I18N.lookup_failed); });
    });
    // Populate the seen_users datalist for typeahead
    (function () {
        if (!data.seen_users || !data.seen_users.length) return;
        var dl = document.createElement('datalist');
        dl.id = 'walkon-seen-users';
        data.seen_users.forEach(function (u) {
            var opt = document.createElement('option');
            opt.value = u;
            dl.appendChild(opt);
        });
        document.body.appendChild(dl);
    })();
    var activeFilter = 'all';
    function applyFilter() {
        var search = (document.getElementById('media-search-input').value || '').toLowerCase();
        var rows = document.querySelectorAll('.media-file-row');
        var visible = 0;
        rows.forEach(function (row) {
            var file = (row.dataset.file || '').toLowerCase();
            var type = row.dataset.type;
            var match = true;
            switch (activeFilter) {
                case 'rewards': match = row.dataset.hasRewards === '1'; break;
                case 'events':  match = row.dataset.hasEvents  === '1'; break;
                case 'walkons': match = row.dataset.hasWalkons === '1'; break;
                case 'unused':  match = row.dataset.total === '0'; break;
                case 'videos':  match = type === 'video'; break;
                default:        match = true;
            }
            if (match && search) match = file.indexOf(search) !== -1;
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        var empty = document.querySelector('.media-empty-filter');
        if (empty) empty.style.display = (visible === 0 && rows.length > 0) ? 'block' : 'none';
    }
    $(document).on('click', '.media-filter-btn', function () {
        $('.media-filter-btn').removeClass('is-active');
        $(this).addClass('is-active');
        activeFilter = $(this).data('filter');
        applyFilter();
    });
    $('#media-search-input').on('input', applyFilter);
    if ($('.sp-notif').length) {
        setTimeout(function () {
            $('.sp-notif').fadeOut(500, function () { $(this).remove(); });
        }, 15000);
    }
    // File input display update
    $(document).on('change', '.media-upload-form input[type="file"]', function () {
        var files = this.files;
        var label = $(this).closest('.media-drop-zone').find('.file-list-label');
        var names = [];
        for (var i = 0; i < files.length; i++) names.push(files[i].name);
        label.text(names.length ? names.join(', ') : I18N.no_files_selected);
    });
    // AJAX upload
    $(document).on('submit', '.media-upload-form', function (e) {
        e.preventDefault();
        var form = $(this);
        var fileInput = form.find('input[type="file"]')[0];
        if (fileInput.files.length === 0) {
            Swal.fire({ icon: 'warning', title: I18N.upload_no_files_title, text: I18N.upload_no_files_text, confirmButtonColor: '#3273dc' });
            return;
        }
        var formData = new FormData(this);
        var statusContainer = form.find('.upload-status-container');
        var statusText = form.find('.upload-status-text');
        var progressPercent = form.find('.upload-progress-percent');
        var progressBar = form.find('.upload-progress');
        var uploadBtn = form.find('.upload-btn');
        var uploadBtnText = form.find('.upload-btn-text');
        statusContainer.show();
        statusText.html('<i class="fas fa-spinner fa-pulse"></i> ' + I18N.uploading_files.replace('%s', fileInput.files.length));
        progressPercent.text('0%');
        progressBar.val(0);
        uploadBtn.prop('disabled', true).addClass('sp-btn-loading');
        uploadBtnText.text(I18N.uploading_btn);
        $.ajax({
            url: '', type: 'POST', data: formData, contentType: false, processData: false,
            xhr: function () {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) {
                        var pct = Math.round((e.loaded / e.total) * 100);
                        progressBar.val(pct);
                        progressPercent.text(pct + '%');
                        statusText.html(pct < 100
                            ? '<i class="fas fa-spinner fa-pulse"></i> ' + I18N.uploading_pct.replace('%s', pct)
                            : '<i class="fas fa-check-circle"></i> ' + I18N.processing_files);
                    }
                }, false);
                return xhr;
            },
            success: function (response) {
                var d = typeof response === 'string' ? JSON.parse(response) : response;
                if (d && !d.success) {
                    statusContainer.hide();
                    uploadBtn.prop('disabled', false).removeClass('sp-btn-loading');
                    uploadBtnText.text(I18N.upload_btn_label);
                    Swal.fire({ icon: 'error', title: I18N.upload_failed_title, html: d.status || I18N.upload_failed_generic, confirmButtonColor: '#3273dc' });
                    return;
                }
                statusText.html('<i class="fas fa-check-circle"></i> ' + I18N.upload_complete);
                progressPercent.text('100%');
                setTimeout(function () { location.reload(); }, 1500);
            },
            error: function () {
                statusContainer.hide();
                uploadBtn.prop('disabled', false).removeClass('sp-btn-loading');
                uploadBtnText.text(I18N.upload_btn_short);
                Swal.fire({ icon: 'error', title: I18N.upload_failed_title, text: I18N.upload_failed_retry, confirmButtonColor: '#3273dc' });
            }
        });
    });
    $(document).on('change', '.media-file-check', function () {
        var checked = $('.media-file-check:checked').length;
        $('.media-delete-selected-btn').prop('disabled', checked < 1);
    });
    // Delete selected (bulk) — refuse if any selection is still linked
    $(document).on('click', '.media-delete-selected-btn', function () {
        var form = $('#mediaDeleteForm');
        var checked = form.find('.media-file-check:checked');
        if (checked.length === 0) return;
        var locked = [];
        checked.each(function () {
            var row = $(this).closest('.media-file-row');
            if (parseInt(row.attr('data-total') || '0', 10) > 0) {
                locked.push({
                    file: row.attr('data-file'),
                    summary: row.find('.media-file-summary').text()
                });
            }
        });
        if (locked.length > 0) {
            var list = locked.map(function (l) { return '<li><strong>' + l.file + '</strong> — ' + l.summary + '</li>'; }).join('');
            Swal.fire({
                icon: 'warning',
                title: I18N.bulk_in_use_title,
                html: I18N.bulk_in_use_intro + '<ul style="text-align:left;margin-top:8px;">' + list + '</ul>'
                    + I18N.bulk_in_use_outro,
            });
            return;
        }
        Swal.fire({
            title: I18N.delete_files_title,
            text: I18N.delete_files_text.replace('%s', checked.length),
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#d33', confirmButtonText: I18N.confirm_delete, cancelButtonText: I18N.cancel
        }).then(function (result) { if (result.isConfirmed) form.submit(); });
    });
    // Delete single
    $(document).on('click', '.media-delete-single', function () {
        var fileName = $(this).data('file');
        Swal.fire({
            title: I18N.delete_file_title,
            text: I18N.delete_file_text.replace('%s', fileName),
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#d33', confirmButtonText: I18N.confirm_delete, cancelButtonText: I18N.cancel
        }).then(function (result) {
            if (result.isConfirmed) {
                $('<input>').attr({ type: 'hidden', name: 'delete_files[]', value: fileName }).appendTo('#mediaDeleteForm');
                $('#mediaDeleteForm').submit();
            }
        });
    });
    // Locked file delete — explain why and point to the right place to unlink
    $(document).on('click', '.media-delete-locked', function () {
        var fileName = $(this).data('file');
        var summary = $(this).data('summary');
        Swal.fire({
            icon: 'info',
            title: I18N.locked_title,
            html: I18N.locked_body.replace(':name', '<strong>' + fileName + '</strong>').replace(':summary', summary),
        });
    });
    // Test playback (existing notify_event flow)
    $(document).on('click', '.media-test-btn', function () {
        var file = $(this).data('file');
        var type = $(this).data('type');
        var eventType = type === 'video' ? 'VIDEO_ALERT' : 'SOUND_ALERT';
        var paramName = type === 'video' ? 'video' : 'sound';
        sendStreamEvent(eventType, paramName, file);
    });
    // Migration button — wire it up to migrate_media.php
    $('#media-migrate-btn').on('click', function () {
        var btn = $(this);
        Swal.fire({
            title: I18N.migrate_title,
            html: I18N.migrate_body,
            icon: 'warning', showCancelButton: true,
            confirmButtonText: I18N.migrate_confirm, cancelButtonText: I18N.cancel
        }).then(function (result) {
            if (!result.isConfirmed) return;
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-pulse"></i> ' + I18N.migrating);
            $.post('/api/migrate_media.php', {}, function (resp) {
                if (resp && resp.success) {
                    Swal.fire({ icon: 'success', title: I18N.migrate_done_title, text: resp.message }).then(function () { location.reload(); });
                } else {
                    Swal.fire({ icon: 'error', title: I18N.migrate_failed_title, text: (resp && resp.message) ? resp.message : I18N.migrate_failed_unknown });
                    btn.prop('disabled', false).html('<i class="fas fa-arrow-right"></i> ' + I18N.migrate_btn_label);
                }
            }, 'json').fail(function () {
                Swal.fire({ icon: 'error', title: I18N.migrate_failed_title, text: I18N.migrate_failed_server });
                btn.prop('disabled', false).html('<i class="fas fa-arrow-right"></i> ' + I18N.migrate_btn_label);
            });
        });
    });
});

function sendStreamEvent(eventType, paramName, fileName) {
    var xhr = new XMLHttpRequest();
    var url = "/api/notify_event.php";
    var params = "event=" + eventType + "&" + paramName + "=" + encodeURIComponent(fileName)
               + "&channel_name=" + encodeURIComponent(window.__MEDIA_CTX.channel)
               + "&api_key=" + encodeURIComponent(window.__MEDIA_CTX.apiKey);
    xhr.open("POST", url, true);
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var response = JSON.parse(xhr.responseText);
                if (response.success) console.log(eventType + " event for " + fileName + " sent.");
                else console.error("Error: " + response.message);
            } catch (e) { console.error("Bad JSON:", e); }
        } else if (xhr.readyState === 4) { console.error("Request failed: " + xhr.responseText); }
    };
    xhr.send(params);
}
</script>
<?php
$scripts = ob_get_clean();
include "layout.php";
?>