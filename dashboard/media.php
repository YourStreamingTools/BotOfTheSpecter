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
require_once __DIR__ . '/upload_helpers.php';
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
        echo json_encode(['success' => false, 'error' => 'Invalid login']);
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
        echo json_encode(['success' => false, 'error' => 'Bot credentials unavailable']);
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
        echo json_encode(['success' => false, 'error' => 'Helix request failed']);
        exit;
    }
    $data = json_decode($raw, true);
    if (empty($data['data'][0]['id'])) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
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

$walkonsByFile = [];  // file => [{user_id, user_name}, ...]
if ($r = $db->query("SELECT twitch_user_id, twitch_user_name, media_file FROM walkons")) {
    while ($row = $r->fetch_assoc()) {
        $walkonsByFile[$row['media_file']][] = [
            'user_id'   => $row['twitch_user_id'],
            'user_name' => $row['twitch_user_name'],
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
    if ($stmt = $db->prepare("SELECT twitch_user_id, twitch_user_name FROM walkons WHERE media_file = ?")) {
        $stmt->bind_param('s', $file); $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $resp['mappings']['walkons'][] = ['user_id' => $r['twitch_user_id'], 'user_name' => $r['twitch_user_name']];
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
            $status .= "Sound alert mapping added.<br>";
            ajax_respond_mappings($file, $db, $isAjax);
        } elseif ($action === 'remove' && $rewardId !== '') {
            $stmt = $db->prepare("DELETE FROM sound_alerts WHERE reward_id = ?");
            $stmt->bind_param('s', $rewardId);
            $stmt->execute(); $stmt->close();
            $status .= "Sound alert mapping removed.<br>";
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
            $status .= "Video alert mapping added.<br>";
            ajax_respond_mappings($file, $db, $isAjax);
        } elseif ($action === 'remove' && $rewardId !== '') {
            $stmt = $db->prepare("DELETE FROM video_alerts WHERE reward_id = ?");
            $stmt->bind_param('s', $rewardId);
            $stmt->execute(); $stmt->close();
            $status .= "Video alert mapping removed.<br>";
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
            $status .= "Twitch event mapping added.<br>";
            ajax_respond_mappings($file, $db, $isAjax);
        } elseif ($action === 'remove' && $eventId !== '') {
            $stmt = $db->prepare("DELETE FROM twitch_sound_alerts WHERE twitch_alert_id = ?");
            $stmt->bind_param('s', $eventId);
            $stmt->execute(); $stmt->close();
            $status .= "Twitch event mapping removed.<br>";
            ajax_respond_mappings($file, $db, $isAjax);
        }
    }
    if ($mediaType === 'walkon_mapping') {
        $file     = $_POST['media_file']       ?? '';
        $userId   = $_POST['twitch_user_id']   ?? '';
        $userName = $_POST['twitch_user_name'] ?? '';
        if ($action === 'add' && $file !== '' && $userId !== '' && $userName !== '') {
            $stmt = $db->prepare("INSERT INTO walkons (twitch_user_id, twitch_user_name, media_file) VALUES (?, ?, ?)
                                  ON DUPLICATE KEY UPDATE media_file = VALUES(media_file), twitch_user_name = VALUES(twitch_user_name)");
            $stmt->bind_param('sss', $userId, $userName, $file);
            $stmt->execute(); $stmt->close();
            $status .= "Walkon added.<br>";
            ajax_respond_mappings($file, $db, $isAjax);
        } elseif ($action === 'remove' && $userId !== '') {
            $stmt = $db->prepare("DELETE FROM walkons WHERE twitch_user_id = ?");
            $stmt->bind_param('s', $userId);
            $stmt->execute(); $stmt->close();
            $status .= "Walkon removed.<br>";
            ajax_respond_mappings($file, $db, $isAjax);
        }
    }
    if (isset($_FILES["filesToUpload"])) {
        $remaining_storage = $max_storage_size - $current_storage_used;
        $uploadStatus = "";
        $targetDir = $media_path;
        $allowedExts = ['mp3', 'mp4'];
        $extLabel = 'MP3/MP4';
        if ($targetDir) {
            foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
                if (empty($tmp_name)) continue;
                $fileName = $_FILES["filesToUpload"]["name"][$key];
                $fileSize = $_FILES["filesToUpload"]["size"][$key];
                $fileError = $_FILES["filesToUpload"]["error"][$key] ?? 0;
                $displayName = htmlspecialchars(basename($fileName));
                if ($fileError !== UPLOAD_ERR_OK) { $uploadStatus .= "Error uploading " . $displayName . ".<br>"; continue; }
                if (!is_uploaded_file($tmp_name)) { $uploadStatus .= "Failed to upload " . $displayName . ". Invalid upload.<br>"; continue; }
                if ($current_storage_used + $fileSize > $max_storage_size) {
                    $uploadStatus .= "Failed to upload " . $displayName . ". Storage limit exceeded.<br>"; continue;
                }
                $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (!in_array($fileType, $allowedExts, true)) {
                    $uploadStatus .= "Failed to upload " . $displayName . ". Only " . $extLabel . " files are allowed.<br>"; continue;
                }
                if (!upload_validate_extension_and_mime($tmp_name, $fileType, $allowedExts)) {
                    $uploadStatus .= "Failed to upload " . $displayName . ". File contents do not match the declared file type.<br>"; continue;
                }
                $safeName = upload_sanitize_filename($fileName, $fileType);
                $target = upload_unique_target($targetDir, $safeName);
                if (move_uploaded_file($tmp_name, $target['path'])) {
                    $current_storage_used += $fileSize;
                    $uploadStatus .= "The file " . htmlspecialchars($target['name']) . " has been uploaded.<br>";
                } else {
                    $uploadStatus .= "Sorry, there was an error uploading " . $displayName . ".<br>";
                }
            }
            $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
            $status .= $uploadStatus;
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => $status,
                'success' => strpos($status, 'Failed') === false && strpos($status, 'Error') === false,
                'storage_used' => $current_storage_used,
                'max_storage' => $max_storage_size,
                'storage_percentage' => $storage_percentage,
            ]);
            exit;
        }
    }
    // File deletion — wipes from the unified library and cleans up every mapping
    // table the file might be referenced from.
    if (isset($_POST['delete_files']) && is_array($_POST['delete_files'])) {
        $deleteStatus = "";
        $db->begin_transaction();
        foreach ($_POST['delete_files'] as $file_to_delete) {
            $filename = basename($file_to_delete);
            $full_path = $media_path . '/' . $filename;
            if (is_file($full_path) && unlink($full_path)) {
                $deleteStatus .= "The file " . htmlspecialchars($filename) . " has been deleted.<br>";
                foreach (['sound_alerts' => 'sound_mapping', 'video_alerts' => 'video_mapping', 'twitch_sound_alerts' => 'sound_mapping', 'walkons' => 'media_file'] as $table => $col) {
                    $stmt = $db->prepare("DELETE FROM $table WHERE $col = ?");
                    $stmt->bind_param('s', $filename);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $deleteStatus .= "Failed to delete " . htmlspecialchars($filename) . ".<br>";
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
        <span class="sp-card-title"><i class="fas fa-upload"></i> Upload Media</span>
    </header>
    <div class="sp-card-body">
        <form action="" method="POST" enctype="multipart/form-data" class="media-upload-form" id="unified-upload-form">
            <input type="hidden" name="media_type" value="media_upload">
            <div class="sp-form-group">
                <small class="media-upload-hint"><i class="fas fa-info-circle"></i> Upload MP3 or MP4 files to your media library. Click a file in the list below to attach it to channel point rewards, Twitch events, or walkons. The same file can power any number of triggers — upload once, use everywhere.</small>
            </div>
            <div class="sp-form-group">
                <label class="media-drop-zone" id="unified-drop-zone">
                    <i class="fas fa-cloud-upload-alt media-drop-zone-icon"></i>
                    <span class="file-list-label">No files selected</span>
                    <div class="media-drop-zone-hint">Click or drag files here</div>
                    <input type="file" name="filesToUpload[]" id="unified-file-input" multiple accept=".mp3,.mp4" hidden>
                </label>
            </div>
            <div class="upload-status-container media-upload-status">
                <div class="sp-alert sp-alert-info">
                    <div class="media-upload-progress-header">
                        <strong class="upload-status-text">Preparing upload...</strong>
                        <span class="upload-progress-percent media-upload-progress-percent">0%</span>
                    </div>
                    <progress class="upload-progress media-upload-progress" value="0" max="100">0%</progress>
                </div>
            </div>
            <button class="sp-btn sp-btn-primary upload-btn media-upload-btn" type="submit">
                <i class="fas fa-upload"></i>
                <span class="upload-btn-text">Upload Media (MP3/MP4)</span>
            </button>
        </form>
    </div>
</div>
<?php if (!$media_migrated): ?>
<!-- Migrate to unified media library -->
<div class="sp-card media-migrate-card">
    <header class="sp-card-header media-migrate-header">
        <span class="sp-card-title"><i class="fas fa-arrow-circle-up"></i> Upgrade to Unified Media Library</span>
    </header>
    <div class="sp-card-body media-migrate-body">
        <h4>How the New System Works</h4>
        <p>BotOfTheSpecter is moving to a unified media library. Instead of keeping separate folders for sound alerts, channel point sounds, Twitch event sounds, and walkons, <strong>all files live in one shared pool</strong>. A single file can be mapped to any combination of triggers — no more duplicating files.</p>
        <ul>
            <li><strong>One upload, any trigger</strong> &mdash; upload a file once and assign it to any channel point reward, Twitch event, or walkon.</li>
            <li><strong>Unified library</strong> &mdash; all audio and video files live in one shared pool.</li>
            <li><strong>Non-destructive migration</strong> &mdash; your existing files are <em>copied</em> into the new library; nothing is deleted from the old locations.</li>
            <li><strong>Walkons auto-linked</strong> &mdash; existing walkon files (named after their Twitch login) are auto-tagged to the right user during migration.</li>
            <li><strong>Beta Bot required</strong> &mdash; after migration, the Stable Bot will no longer trigger media. You must run the Beta Bot for alerts to work.</li>
        </ul>
        <button type="button" id="media-migrate-btn" class="sp-btn sp-btn-warning">
            <i class="fas fa-arrow-right"></i> Migrate to Unified Media Library
        </button>
    </div>
</div>
<?php else: ?>
<div class="sp-alert sp-alert-success media-migrated-notice">
    <i class="fas fa-check-circle"></i> <strong>Using Unified Media Library</strong> &mdash; Your audio and video files are managed through the shared library. Requires Beta Bot.
</div>
<?php endif; ?>
<!-- Filter + search bar -->
<div class="media-filter-bar">
    <button type="button" class="sp-btn sp-btn-ghost media-filter-btn is-active" data-filter="all">All</button>
    <button type="button" class="sp-btn sp-btn-ghost media-filter-btn" data-filter="rewards">With rewards</button>
    <button type="button" class="sp-btn sp-btn-ghost media-filter-btn" data-filter="events">With events</button>
    <button type="button" class="sp-btn sp-btn-ghost media-filter-btn" data-filter="walkons">Walkons</button>
    <button type="button" class="sp-btn sp-btn-ghost media-filter-btn" data-filter="unused">Unused</button>
    <button type="button" class="sp-btn sp-btn-ghost media-filter-btn" data-filter="videos">Videos</button>
    <input type="search" class="sp-input media-search-input" id="media-search-input" placeholder="Search files…">
</div>
<!-- Files list -->
<div class="sp-card media-files-card">
    <header class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-folder-open"></i> Your Media Library</span>
        <button class="sp-btn sp-btn-danger media-delete-selected-btn" disabled>
            <i class="fas fa-trash"></i> <span>Delete Selected</span>
        </button>
    </header>
    <div class="sp-card-body">
        <?php if (empty($all_media_files)): ?>
            <div class="media-empty-state">
                <p>No media files uploaded yet. Upload MP3 or MP4 files using the form above.</p>
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
                    $totalCount  = $rewardCount + $eventCount + $walkonCount;
                    $summaryParts = [];
                    if ($rewardCount > 0) $summaryParts[] = $rewardCount . ' reward' . ($rewardCount === 1 ? '' : 's');
                    if ($eventCount  > 0) $summaryParts[] = $eventCount . ' event' . ($eventCount === 1 ? '' : 's');
                    if ($walkonCount > 0) $summaryParts[] = $walkonCount . ' walkon' . ($walkonCount === 1 ? '' : 's');
                    $summary = empty($summaryParts) ? 'Unused' : implode(' · ', $summaryParts);
                ?>
                <li class="media-file-row"
                    data-file="<?php echo htmlspecialchars($file); ?>"
                    data-type="<?php echo htmlspecialchars($type); ?>"
                    data-has-rewards="<?php echo $rewardCount > 0 ? '1' : '0'; ?>"
                    data-has-events="<?php echo $eventCount > 0 ? '1' : '0'; ?>"
                    data-has-walkons="<?php echo $walkonCount > 0 ? '1' : '0'; ?>"
                    data-total="<?php echo $totalCount; ?>">
                    <input type="checkbox" class="media-file-check" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>">
                    <button type="button" class="media-file-name" data-file="<?php echo htmlspecialchars($file); ?>">
                        <?php echo htmlspecialchars($file); ?>
                    </button>
                    <span class="media-file-meta"><?php echo strtoupper(pathinfo($file, PATHINFO_EXTENSION)); ?> · <?php echo format_bytes($size); ?></span>
                    <span class="media-file-summary<?php echo $totalCount === 0 ? ' is-unused' : ''; ?>"><?php echo htmlspecialchars($summary); ?></span>
                    <button type="button" class="sp-btn sp-btn-primary sp-btn-sm media-test-btn" data-file="<?php echo htmlspecialchars($file); ?>" data-type="<?php echo $type; ?>" title="Test playback">
                        <i class="fas fa-play"></i>
                    </button>
                    <button type="button" class="sp-btn sp-btn-danger sp-btn-sm media-delete-single" data-file="<?php echo htmlspecialchars($file); ?>" title="Delete file">
                        <i class="fas fa-trash"></i>
                    </button>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="media-empty-filter" style="display:none;">
                <p>No files match the current filter.</p>
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
            <button type="button" class="sp-modal-close" id="media-modal-close" aria-label="Close">&times;</button>
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

$(document).ready(function () {
    var data = window.__MEDIA_DATA;
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
        return ext === 'mp4' ? 'video' : (ext === 'mp3' ? 'audio' : 'other');
    }
    function rewardTitle(id) {
        return data.reward_titles[id] || '(unknown reward)';
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
            html += '<em class="mapping-empty">No mappings yet.</em>';
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
        var rewards     = (type === 'video' ? data.video_alerts[file] : data.sound_alerts[file]) || [];
        var events      = data.twitch_events[file] || [];
        var walkons     = data.walkons[file]       || [];
        var alertBuilder= data.alert_builder[file] || [];
        var html = '';
        // Header
        html += '<div class="media-modal-fileinfo">' + escapeHtml(type === 'video' ? 'MP4 video' : 'MP3 audio') + '</div>';
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
            addReward += '<option value="">+ Add channel point reward…</option>';
            availRewards.forEach(function (r) {
                addReward += '<option value="' + escapeHtml(r.reward_id) + '">' + escapeHtml(r.reward_title) + '</option>';
            });
            addReward += '</select>';
        }
        html += renderSection(type === 'video' ? 'Channel Point Rewards (Video)' : 'Channel Point Rewards', rewardChips, addReward, false);
        // Twitch events (audio only — no MP4 events today)
        if (type !== 'video') {
            var availEvents = availableEvents(events);
            var eventChips = events.map(function (eid) {
                return { label: eid, removeData: { kind: 'event', 'event-id': eid } };
            });
            var addEvent = '';
            if (availEvents.length > 0) {
                addEvent = '<select class="sp-select mapping-add-select" data-add-kind="event">';
                addEvent += '<option value="">+ Add Twitch event…</option>';
                availEvents.forEach(function (e) {
                    addEvent += '<option value="' + escapeHtml(e) + '">' + escapeHtml(e) + '</option>';
                });
                addEvent += '</select>';
            }
            html += renderSection('Twitch Events', eventChips, addEvent, false);
        }
        // Walkons (audio only)
        if (type !== 'video') {
            var walkonChips = walkons.map(function (w) {
                return { label: '@' + w.user_name, removeData: { kind: 'walkon', 'user-id': w.user_id } };
            });
            var addWalkon = ''
                + '<div class="walkon-add-wrap">'
                + '  <input type="text" class="sp-input walkon-add-input" placeholder="Twitch username…" autocomplete="off" list="walkon-seen-users">'
                + '  <button type="button" class="sp-btn sp-btn-primary sp-btn-sm walkon-add-confirm">+ Add user</button>'
                + '  <span class="walkon-add-status"></span>'
                + '</div>';
            html += renderSection('Walkons', walkonChips, addWalkon, false);
        }
        // Alert builder (read-only)
        if (alertBuilder.length > 0) {
            var abChips = alertBuilder.map(function (a) {
                return { label: a.category + ' · ' + a.variant + ' (' + a.type + ')' };
            });
            html += renderSection('Used by Alert Builder', abChips, '', true);
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
        var total = r + e + w;
        var parts = [];
        if (r > 0) parts.push(r + ' reward' + (r === 1 ? '' : 's'));
        if (e > 0) parts.push(e + ' event' + (e === 1 ? '' : 's'));
        if (w > 0) parts.push(w + ' walkon' + (w === 1 ? '' : 's'));
        var summaryEl = row.querySelector('.media-file-summary');
        summaryEl.textContent = total === 0 ? 'Unused' : parts.join(' · ');
        summaryEl.classList.toggle('is-unused', total === 0);
        row.dataset.hasRewards = r > 0 ? '1' : '0';
        row.dataset.hasEvents  = e > 0 ? '1' : '0';
        row.dataset.hasWalkons = w > 0 ? '1' : '0';
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
        var input  = $(this).closest('.walkon-add-wrap').find('.walkon-add-input');
        var status = $(this).closest('.walkon-add-wrap').find('.walkon-add-status');
        var raw    = (input.val() || '').trim().replace(/^@/, '').toLowerCase();
        if (!raw) return;
        status.text('Looking up @' + raw + '…');
        // Helix resolves both seen_users and unknown logins to a Twitch user_id.
        // Going through Helix unconditionally keeps the path uniform.
        $.get('', { action: 'helix_lookup_user', login: raw }, function (resp) {
            if (!resp || !resp.success) {
                status.text(resp && resp.error ? resp.error : 'Lookup failed');
                return;
            }
            postMapping({
                media_type: 'walkon_mapping',
                action: 'add',
                media_file: activeFile,
                twitch_user_id: resp.user_id,
                twitch_user_name: resp.user_name
            }, function () { input.val(''); status.text(''); });
        }, 'json').fail(function () { status.text('Lookup failed'); });
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
        label.text(names.length ? names.join(', ') : 'No files selected');
    });
    // AJAX upload
    $(document).on('submit', '.media-upload-form', function (e) {
        e.preventDefault();
        var form = $(this);
        var fileInput = form.find('input[type="file"]')[0];
        if (fileInput.files.length === 0) {
            Swal.fire({ icon: 'warning', title: 'No Files Selected', text: 'Please select at least one file to upload.', confirmButtonColor: '#3273dc' });
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
        statusText.html('<i class="fas fa-spinner fa-pulse"></i> Uploading ' + fileInput.files.length + ' file(s)...');
        progressPercent.text('0%');
        progressBar.val(0);
        uploadBtn.prop('disabled', true).addClass('sp-btn-loading');
        uploadBtnText.text('Uploading...');
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
                            ? '<i class="fas fa-spinner fa-pulse"></i> Uploading... (' + pct + '%)'
                            : '<i class="fas fa-check-circle"></i> Processing files on server...');
                    }
                }, false);
                return xhr;
            },
            success: function (response) {
                var d = typeof response === 'string' ? JSON.parse(response) : response;
                if (d && !d.success) {
                    statusContainer.hide();
                    uploadBtn.prop('disabled', false).removeClass('sp-btn-loading');
                    uploadBtnText.text('Upload Media (MP3/MP4)');
                    Swal.fire({ icon: 'error', title: 'Upload Failed', html: d.status || 'An error occurred during upload.', confirmButtonColor: '#3273dc' });
                    return;
                }
                statusText.html('<i class="fas fa-check-circle"></i> Upload completed successfully!');
                progressPercent.text('100%');
                setTimeout(function () { location.reload(); }, 1500);
            },
            error: function () {
                statusContainer.hide();
                uploadBtn.prop('disabled', false).removeClass('sp-btn-loading');
                uploadBtnText.text('Upload');
                Swal.fire({ icon: 'error', title: 'Upload Failed', text: 'An error occurred during upload. Please try again.', confirmButtonColor: '#3273dc' });
            }
        });
    });
    $(document).on('change', '.media-file-check', function () {
        var checked = $('.media-file-check:checked').length;
        $('.media-delete-selected-btn').prop('disabled', checked < 1);
    });
    // Delete selected (bulk)
    $(document).on('click', '.media-delete-selected-btn', function () {
        var form = $('#mediaDeleteForm');
        var checked = form.find('.media-file-check:checked');
        if (checked.length === 0) return;
        Swal.fire({
            title: 'Delete Files?',
            text: 'Are you sure you want to delete the selected ' + checked.length + ' file(s)? All mappings will also be removed.',
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#d33', confirmButtonText: 'Yes, delete', cancelButtonText: 'Cancel'
        }).then(function (result) { if (result.isConfirmed) form.submit(); });
    });
    // Delete single
    $(document).on('click', '.media-delete-single', function () {
        var fileName = $(this).data('file');
        Swal.fire({
            title: 'Delete File?',
            text: 'Are you sure you want to delete "' + fileName + '"? All mappings will also be removed.',
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#d33', confirmButtonText: 'Yes, delete', cancelButtonText: 'Cancel'
        }).then(function (result) {
            if (result.isConfirmed) {
                $('<input>').attr({ type: 'hidden', name: 'delete_files[]', value: fileName }).appendTo('#mediaDeleteForm');
                $('#mediaDeleteForm').submit();
            }
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
            title: 'Migrate to Unified Library?',
            html: '<p>This copies your existing sound alerts, video alerts, and walkon files into the unified library. Existing walkons are auto-tagged to their Twitch user.</p><p><strong>Original files are not deleted</strong> — migration is non-destructive.</p>',
            icon: 'info', showCancelButton: true,
            confirmButtonText: 'Yes, migrate', cancelButtonText: 'Cancel'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-pulse"></i> Migrating…');
            $.post('migrate_media.php', {}, function (resp) {
                if (resp && resp.success) {
                    Swal.fire({ icon: 'success', title: 'Migration Complete', text: resp.message }).then(function () { location.reload(); });
                } else {
                    Swal.fire({ icon: 'error', title: 'Migration Failed', text: (resp && resp.message) ? resp.message : 'Unknown error' });
                    btn.prop('disabled', false).html('<i class="fas fa-arrow-right"></i> Migrate to Unified Media Library');
                }
            }, 'json').fail(function () {
                Swal.fire({ icon: 'error', title: 'Migration Failed', text: 'Server error during migration.' });
                btn.prop('disabled', false).html('<i class="fas fa-arrow-right"></i> Migrate to Unified Media Library');
            });
        });
    });
});

function sendStreamEvent(eventType, paramName, fileName) {
    var xhr = new XMLHttpRequest();
    var url = "notify_event.php";
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