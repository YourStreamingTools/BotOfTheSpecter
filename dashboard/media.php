<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
ini_set('max_execution_time', 300);

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

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
$stmt = $db->prepare("SELECT timezone, media_migrated FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$media_migrated = (bool)($channelData['media_migrated'] ?? false);
$audio_path = $media_migrated ? $media_path : $soundalert_path;
$stmt->close();
date_default_timezone_set($timezone);

$status = '';
$activeTab = $_POST['media_type'] ?? 'sound-alerts';
// Normalize active tab from media_type POST values to tab IDs
$tabMap = [
    'sound_alert' => 'sound-alerts',
    'sound_alert_mapping' => 'sound-alerts',
    'video_alert' => 'video-alerts',
    'video_alert_mapping' => 'video-alerts',
    'walkon' => 'walkons',
    'twitch_event' => 'twitch-events',
    'twitch_event_mapping' => 'twitch-events',
];
$activeTab = $tabMap[$activeTab] ?? 'sound-alerts';

$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

// Fetch sound alert mappings
$soundAlertMappings = [];
$getSoundAlerts = $db->prepare("SELECT sound_mapping, reward_id FROM sound_alerts");
$getSoundAlerts->execute();
$getSoundAlerts->bind_result($sound_mapping, $reward_id);
while ($getSoundAlerts->fetch()) {
    $soundAlertMappings[$sound_mapping] = $reward_id;
}
$getSoundAlerts->close();

// Fetch video alert mappings
$videoAlertMappings = [];
$videoAlerts = [];
if ($vResult = $db->query("SELECT video_mapping, reward_id FROM video_alerts")) {
    while ($row = $vResult->fetch_assoc()) {
        $videoAlerts[] = $row;
        $videoAlertMappings[$row['video_mapping']] = $row['reward_id'];
    }
    $vResult->free();
}

// Fetch twitch event alert mappings
$twitchSoundAlertMappings = [];
$stmt = $db->prepare("SELECT sound_mapping, twitch_alert_id FROM twitch_sound_alerts");
$stmt->execute();
$stmt->bind_result($file_name, $twitch_event);
while ($stmt->fetch()) {
    $twitchSoundAlertMappings[$file_name] = $twitch_event;
}
$stmt->close();

// Build cross-mapping exclusion lists for channel point rewards
$videoMappedRewards = [];
foreach ($videoAlertMappings as $mapping => $rid) {
    $videoMappedRewards[] = $rid;
}
$soundMappedRewards = [];
foreach ($soundAlertMappings as $mapping => $rid) {
    $soundMappedRewards[] = $rid;
}

// Create reward_id => reward_title lookup
$rewardIdToTitle = [];
foreach ($channelPointRewards as $reward) {
    $rewardIdToTitle[$reward['reward_id']] = $reward['reward_title'];
}

// ─── POST Handlers ───────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mediaType = $_POST['media_type'] ?? '';

    // Sound Alert mapping
    if ($mediaType === 'sound_alert_mapping' && isset($_POST['sound_file'], $_POST['reward_id'])) {
        $soundFile = htmlspecialchars($_POST['sound_file']);
        $rewardId = $_POST['reward_id'];
        $db->begin_transaction();
        $checkExisting = $db->prepare("SELECT 1 FROM sound_alerts WHERE sound_mapping = ?");
        $checkExisting->bind_param('s', $soundFile);
        $checkExisting->execute();
        $checkExisting->store_result();
        if ($checkExisting->num_rows > 0) {
            if ($rewardId) {
                $updateMapping = $db->prepare("UPDATE sound_alerts SET reward_id = ? WHERE sound_mapping = ?");
                $updateMapping->bind_param('ss', $rewardId, $soundFile);
                if (!$updateMapping->execute()) {
                    $status .= "Failed to update mapping for '" . $soundFile . "'.<br>";
                } else {
                    $status .= "Mapping for '" . $soundFile . "' updated.<br>";
                }
                $updateMapping->close();
            } else {
                $deleteMapping = $db->prepare("DELETE FROM sound_alerts WHERE sound_mapping = ?");
                $deleteMapping->bind_param('s', $soundFile);
                if (!$deleteMapping->execute()) {
                    $status .= "Failed to remove mapping for '" . $soundFile . "'.<br>";
                } else {
                    $status .= "Mapping for '" . $soundFile . "' removed.<br>";
                }
                $deleteMapping->close();
            }
        } else {
            if ($rewardId) {
                $insertMapping = $db->prepare("INSERT INTO sound_alerts (sound_mapping, reward_id) VALUES (?, ?)");
                $insertMapping->bind_param('ss', $soundFile, $rewardId);
                if (!$insertMapping->execute()) {
                    $status .= "Failed to create mapping for '" . $soundFile . "'.<br>";
                } else {
                    $status .= "Mapping for '" . $soundFile . "' created.<br>";
                }
                $insertMapping->close();
            }
        }
        $checkExisting->close();
        $db->commit();
        // Re-fetch mappings
        $soundAlertMappings = [];
        $getSoundAlerts = $db->prepare("SELECT sound_mapping, reward_id FROM sound_alerts");
        $getSoundAlerts->execute();
        $getSoundAlerts->bind_result($sound_mapping, $reward_id);
        while ($getSoundAlerts->fetch()) {
            $soundAlertMappings[$sound_mapping] = $reward_id;
        }
        $getSoundAlerts->close();
    }

    // Video Alert mapping
    if ($mediaType === 'video_alert_mapping' && isset($_POST['video_file'], $_POST['reward_id'])) {
        $videoFile = htmlspecialchars($_POST['video_file']);
        $rewardId = $_POST['reward_id'];
        $stmt = $db->prepare("SELECT 1 FROM video_alerts WHERE video_mapping = ?");
        $stmt->bind_param("s", $videoFile);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        if ($exists) {
            if ($rewardId) {
                $stmt = $db->prepare("UPDATE video_alerts SET reward_id = ? WHERE video_mapping = ?");
                $stmt->bind_param("ss", $rewardId, $videoFile);
                if (!$stmt->execute()) {
                    $status .= "Failed to update mapping for '" . $videoFile . "'.<br>";
                } else {
                    $status .= "Mapping for '" . $videoFile . "' updated.<br>";
                }
                $stmt->close();
            } else {
                $stmt = $db->prepare("DELETE FROM video_alerts WHERE video_mapping = ?");
                $stmt->bind_param("s", $videoFile);
                if (!$stmt->execute()) {
                    $status .= "Failed to remove mapping for '" . $videoFile . "'.<br>";
                } else {
                    $status .= "Mapping for '" . $videoFile . "' removed.<br>";
                }
                $stmt->close();
            }
        } else {
            if ($rewardId) {
                $stmt = $db->prepare("INSERT INTO video_alerts (video_mapping, reward_id) VALUES (?, ?)");
                $stmt->bind_param("ss", $videoFile, $rewardId);
                if (!$stmt->execute()) {
                    $status .= "Failed to create mapping for '" . $videoFile . "'.<br>";
                } else {
                    $status .= "Mapping for '" . $videoFile . "' created.<br>";
                }
                $stmt->close();
            }
        }
        // Re-fetch mappings
        $videoAlertMappings = [];
        $videoAlerts = [];
        if ($vResult = $db->query("SELECT video_mapping, reward_id FROM video_alerts")) {
            while ($row = $vResult->fetch_assoc()) {
                $videoAlerts[] = $row;
                $videoAlertMappings[$row['video_mapping']] = $row['reward_id'];
            }
            $vResult->free();
        }
    }

    // Twitch Event mapping
    if ($mediaType === 'twitch_event_mapping' && isset($_POST['sound_file'], $_POST['twitch_alert_id'])) {
        $soundFile = htmlspecialchars($_POST['sound_file']);
        $rewardId = htmlspecialchars($_POST['twitch_alert_id'] ?? '');
        $db->begin_transaction();
        $checkExisting = $db->prepare("SELECT 1 FROM twitch_sound_alerts WHERE sound_mapping = ?");
        $checkExisting->bind_param('s', $soundFile);
        $checkExisting->execute();
        $result = $checkExisting->get_result();
        if ($result->num_rows > 0) {
            if (!empty($rewardId)) {
                $updateMapping = $db->prepare("UPDATE twitch_sound_alerts SET twitch_alert_id = ? WHERE sound_mapping = ?");
                $updateMapping->bind_param('ss', $rewardId, $soundFile);
                if (!$updateMapping->execute()) {
                    $status .= "Failed to update mapping for '" . $soundFile . "'.<br>";
                } else {
                    $status .= "Mapping for '" . $soundFile . "' updated.<br>";
                }
            } else {
                $deleteMapping = $db->prepare("DELETE FROM twitch_sound_alerts WHERE sound_mapping = ?");
                $deleteMapping->bind_param('s', $soundFile);
                if (!$deleteMapping->execute()) {
                    $status .= "Failed to remove mapping for '" . $soundFile . "'.<br>";
                } else {
                    $status .= "Mapping for '" . $soundFile . "' removed.<br>";
                }
            }
        } else {
            if (!empty($rewardId)) {
                $insertMapping = $db->prepare("INSERT INTO twitch_sound_alerts (sound_mapping, twitch_alert_id) VALUES (?, ?)");
                $insertMapping->bind_param('ss', $soundFile, $rewardId);
                if (!$insertMapping->execute()) {
                    $status .= "Failed to create mapping for '" . $soundFile . "'.<br>";
                } else {
                    $status .= "Mapping for '" . $soundFile . "' created.<br>";
                }
            }
        }
        $db->commit();
        // Re-fetch mappings
        $twitchSoundAlertMappings = [];
        $stmt = $db->prepare("SELECT sound_mapping, twitch_alert_id FROM twitch_sound_alerts");
        $stmt->execute();
        $stmt->bind_result($file_name, $twitch_event);
        while ($stmt->fetch()) {
            $twitchSoundAlertMappings[$file_name] = $twitch_event;
        }
        $stmt->close();
    }

    // File uploads
    if (isset($_FILES["filesToUpload"])) {
        $remaining_storage = $max_storage_size - $current_storage_used;
        $uploadStatus = "";
        switch ($mediaType) {
            case 'sound_alert':
                $targetDir = $audio_path;
                $allowedExts = ['mp3'];
                $extLabel = 'MP3';
                break;
            case 'video_alert':
                $targetDir = $videoalert_path;
                $allowedExts = ['mp4'];
                $extLabel = 'MP4';
                break;
            case 'walkon':
                $targetDir = $walkon_path;
                $allowedExts = ['mp3', 'mp4'];
                $extLabel = 'MP3/MP4';
                break;
            case 'twitch_event':
                $targetDir = $audio_path;
                $allowedExts = ['mp3'];
                $extLabel = 'MP3';
                break;
            default:
                $targetDir = null;
                break;
        }
        if ($targetDir) {
            foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
                if (empty($tmp_name)) continue;
                $fileName = $_FILES["filesToUpload"]["name"][$key];
                $fileSize = $_FILES["filesToUpload"]["size"][$key];
                $fileError = $_FILES["filesToUpload"]["error"][$key] ?? 0;
                if ($fileError !== UPLOAD_ERR_OK) {
                    $uploadStatus .= "Error uploading " . htmlspecialchars(basename($fileName)) . ".<br>";
                    continue;
                }
                if ($current_storage_used + $fileSize > $max_storage_size) {
                    $uploadStatus .= "Failed to upload " . htmlspecialchars(basename($fileName)) . ". Storage limit exceeded.<br>";
                    continue;
                }
                $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (!in_array($fileType, $allowedExts)) {
                    $uploadStatus .= "Failed to upload " . htmlspecialchars(basename($fileName)) . ". Only " . $extLabel . " files are allowed.<br>";
                    continue;
                }
                $targetFile = $targetDir . '/' . basename($fileName);
                if (move_uploaded_file($tmp_name, $targetFile)) {
                    $current_storage_used += $fileSize;
                    $uploadStatus .= "The file " . htmlspecialchars(basename($fileName)) . " has been uploaded.<br>";
                } else {
                    $uploadStatus .= "Sorry, there was an error uploading " . htmlspecialchars(basename($fileName)) . ".<br>";
                }
            }
            $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
            $status .= $uploadStatus;
        }
        // AJAX response
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
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

    // File deletion
    if (isset($_POST['delete_files']) && is_array($_POST['delete_files'])) {
        $deleteStatus = "";
        $extraTable = null;
        $extraColumn = null;
        switch ($mediaType) {
            case 'sound_alert':
                $targetDir = $audio_path;
                $dbTable = 'sound_alerts';
                $dbColumn = 'sound_mapping';
                // Files are shared — also clear any Twitch event mappings
                $extraTable = 'twitch_sound_alerts';
                $extraColumn = 'sound_mapping';
                break;
            case 'video_alert':
                $targetDir = $videoalert_path;
                $dbTable = 'video_alerts';
                $dbColumn = 'video_mapping';
                break;
            case 'walkon':
                $targetDir = $walkon_path;
                $dbTable = null;
                $dbColumn = null;
                break;
            case 'twitch_event':
                // Files now live in the shared audio pool
                $targetDir = $audio_path;
                $dbTable = 'twitch_sound_alerts';
                $dbColumn = 'sound_mapping';
                // Also clear channel-point sound alert mappings for this file
                $extraTable = 'sound_alerts';
                $extraColumn = 'sound_mapping';
                break;
            default:
                $targetDir = null;
                break;
        }
        if ($targetDir) {
            $db->begin_transaction();
            foreach ($_POST['delete_files'] as $file_to_delete) {
                $filename = basename($file_to_delete);
                $full_path = $targetDir . '/' . $filename;
                if (is_file($full_path) && unlink($full_path)) {
                    $deleteStatus .= "The file " . htmlspecialchars($filename) . " has been deleted.<br>";
                    if ($dbTable) {
                        $deleteMapping = $db->prepare("DELETE FROM $dbTable WHERE $dbColumn = ?");
                        $deleteMapping->bind_param('s', $filename);
                        if ($deleteMapping->execute() && $deleteMapping->affected_rows > 0) {
                            $deleteStatus .= "Mapping for " . htmlspecialchars($filename) . " removed.<br>";
                        }
                        $deleteMapping->close();
                    }
                    if ($extraTable) {
                        $extraMapping = $db->prepare("DELETE FROM $extraTable WHERE $extraColumn = ?");
                        $extraMapping->bind_param('s', $filename);
                        $extraMapping->execute();
                        $extraMapping->close();
                    }
                } else {
                    $deleteStatus .= "Failed to delete " . htmlspecialchars($filename) . ".<br>";
                }
            }
            $db->commit();
            $audio_dirs_for_calc = $media_migrated ? [$media_path] : [$soundalert_path, $twitch_sound_alert_path];
            $current_storage_used = calculateStorageUsed(array_merge([$walkon_path, $videoalert_path], $audio_dirs_for_calc));
            $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
            $status .= $deleteStatus;
        }
    }
}

// ─── Scan directories for file listings ──────────────────────────────────────

$soundalert_files = $media_migrated
    ? array_diff(scandir($media_path), array('.', '..'))
    : array_diff(scandir($soundalert_path), array('.', '..', 'twitch'));
$videoalert_files = array_diff(scandir($videoalert_path), array('.', '..'));
$walkon_files = array_diff(scandir($walkon_path), array('.', '..'));
$twitch_sound_files = array_diff(scandir($twitch_sound_alert_path), array('.', '..'));

function formatFileNameWithExt($fileName) {
    $fileInfo = pathinfo($fileName);
    $name = basename($fileName, '.' . $fileInfo['extension']);
    $ext = strtoupper($fileInfo['extension']);
    return $name . " (" . $ext . ")";
}

// ─── HTML Output ─────────────────────────────────────────────────────────────

ob_start();
?>
<!-- Storage Usage (shared across all tabs) -->
<div class="sp-alert sp-alert-info" style="margin-bottom:1.25rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
        <span><i class="fas fa-database" style="margin-right:0.4rem;"></i> <strong><?php echo t('alerts_storage_usage'); ?>:</strong></span>
        <span><?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB / <?php echo round($max_storage_size / 1024 / 1024, 2); ?>MB (<?php echo round($storage_percentage, 2); ?>%)</span>
    </div>
    <progress class="progress" value="<?php echo $storage_percentage; ?>" max="100" style="width:100%;"></progress>
</div>
<?php if (!empty($status)): ?>
    <div class="sp-alert sp-alert-info sp-notif" style="margin-bottom:1.25rem;">
        <?php echo $status; ?>
    </div>
<?php endif; ?>
<!-- Unified Upload Card -->
<div class="sp-card" style="margin-bottom:1.5rem;">
    <header class="sp-card-header">
        <span class="sp-card-title"><i class="fas fa-upload"></i> Upload Media</span>
    </header>
    <div class="sp-card-body">
        <form action="" method="POST" enctype="multipart/form-data" class="media-upload-form" id="unified-upload-form">
            <input type="hidden" name="media_type" id="unified-media-type" value="sound_alert">
            <div class="sp-form-group" style="margin-bottom:1rem;">
                <label style="font-weight:600;display:block;margin-bottom:0.5rem;">File Type</label>
                <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                    <button type="button" class="sp-btn sp-btn-primary upload-type-btn" data-type="sound_alert" data-accept=".mp3" data-label="Audio (MP3)">
                        <i class="fas fa-volume-up"></i> Audio (MP3)
                    </button>
                    <button type="button" class="sp-btn sp-btn-outline upload-type-btn" data-type="video_alert" data-accept=".mp4" data-label="Video (MP4)">
                        <i class="fas fa-film"></i> Video (MP4)
                    </button>
                    <button type="button" class="sp-btn sp-btn-outline upload-type-btn" data-type="walkon" data-accept=".mp3,.mp4" data-label="Walk-on (MP3/MP4)">
                        <i class="fas fa-door-open"></i> Walk-on (MP3/MP4)
                    </button>
                </div>
                <small style="color:var(--text-muted);margin-top:0.5rem;display:block;"><i class="fas fa-info-circle"></i> Audio files are shared — once uploaded you can map the same file to a Channel Point reward and/or a Twitch Event from their tabs below.</small>
            </div>
            <div class="sp-form-group">
                <label class="media-drop-zone" id="unified-drop-zone">
                    <i class="fas fa-cloud-upload-alt" style="font-size:2rem;margin-bottom:0.5rem;display:block;"></i>
                    <span class="file-list-label">No files selected</span>
                    <div style="margin-top:0.5rem;font-size:0.8rem;color:var(--text-muted);">Click or drag files here</div>
                    <input type="file" name="filesToUpload[]" id="unified-file-input" multiple accept=".mp3" style="display:none;">
                </label>
            </div>
            <div class="upload-status-container" style="display:none;margin-bottom:1rem;">
                <div class="sp-alert sp-alert-info">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                        <strong class="upload-status-text">Preparing upload...</strong>
                        <span class="upload-progress-percent" style="font-weight:600;">0%</span>
                    </div>
                    <progress class="upload-progress" value="0" max="100" style="width:100%;">0%</progress>
                </div>
            </div>
            <button class="sp-btn sp-btn-primary upload-btn" type="submit" style="width:100%;font-size:1.1rem;">
                <i class="fas fa-upload"></i>
                <span class="upload-btn-text">Upload Audio (MP3)</span>
            </button>
        </form>
    </div>
</div>
<?php if (!$media_migrated): ?>
<!-- Migrate to unified media library (Coming Soon in v5.8) -->
<div class="sp-card" style="margin-bottom:1.5rem;border-left:4px solid var(--yellow);">
    <header class="sp-card-header" style="display:flex;align-items:center;gap:0.6rem;">
        <span class="sp-card-title"><i class="fas fa-arrow-circle-up"></i> Upgrade to Unified Media Library</span>
        <span style="background:var(--yellow);color:#000;font-size:0.7rem;font-weight:700;letter-spacing:.04em;padding:2px 8px;border-radius:99px;text-transform:uppercase;">Coming in v5.8</span>
    </header>
    <div class="sp-card-body">
        <h4 style="margin:0 0 0.6rem;">How the New System Works</h4>
        <p style="margin-bottom:0.75rem;">Starting in <strong>v5.8</strong>, BotOfTheSpecter introduces a unified media library. Instead of keeping separate folders for Sound Alerts, Channel Point sounds, and Twitch Event sounds, <strong>all audio files live in one shared pool</strong>. A single MP3 can be mapped to a Channel Point reward <em>and</em> a Twitch Event simultaneously &mdash; no more duplicating files.</p>
        <ul style="margin:0 0 1rem 1.25rem;line-height:1.8;">
            <li><strong>One upload, any trigger</strong> &mdash; upload a file once and assign it to any Channel Point or Twitch Event.</li>
            <li><strong>Unified library</strong> &mdash; all audio files live in one shared pool, no matter which trigger you assign them to.</li>
            <li><strong>Non-destructive migration</strong> &mdash; your existing files are <em>copied</em> into the new library; nothing is deleted from the old locations.</li>
            <li><strong>Beta Bot required</strong> &mdash; after migration, the Stable Bot will no longer trigger audio files. You must run the Beta Bot for alerts to work.</li>
        </ul>
        <div class="sp-alert sp-alert-info" style="margin:0 0 1.25rem;">
            <i class="fas fa-info-circle"></i> The migration tool will be available when <strong>v5.8</strong> is released. No action is needed right now.
        </div>
        <button class="sp-btn sp-btn-warning" disabled style="font-size:1rem;opacity:0.5;cursor:not-allowed;">
            <i class="fas fa-clock"></i> Migrate to New Media Library &mdash; Coming Soon
        </button>
    </div>
</div>
<?php else: ?>
<div class="sp-alert sp-alert-success" style="margin-bottom:1.25rem;">
    <i class="fas fa-check-circle"></i> <strong>Using Unified Media Library</strong> &mdash; Your audio files are managed through the new shared library. Requires Beta Bot.
</div>
<?php endif; ?>
<!-- Tabs Navigation -->
<ul class="sp-tabs-nav" style="flex-wrap:wrap; margin-bottom:1.25rem;">
    <li class="<?php echo $activeTab === 'sound-alerts' ? 'is-active' : ''; ?>" data-tab="sound-alerts">
        <a><i class="fas fa-volume-up"></i><span><?php echo t('navbar_sound_alerts'); ?></span></a>
    </li>
    <li class="<?php echo $activeTab === 'video-alerts' ? 'is-active' : ''; ?>" data-tab="video-alerts">
        <a><i class="fas fa-film"></i><span><?php echo t('navbar_video_alerts'); ?></span></a>
    </li>
    <li class="<?php echo $activeTab === 'walkons' ? 'is-active' : ''; ?>" data-tab="walkons">
        <a><i class="fas fa-door-open"></i><span><?php echo t('navbar_walkon_alerts'); ?></span></a>
    </li>
    <li class="<?php echo $activeTab === 'twitch-events' ? 'is-active' : ''; ?>" data-tab="twitch-events">
        <a><i class="fab fa-twitch"></i><span><?php echo t('modules_tab_twitch_event_alerts'); ?></span></a>
    </li>
</ul>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Sound Alerts Tab -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="sound-alerts" style="display:<?php echo $activeTab === 'sound-alerts' ? 'block' : 'none'; ?>;">
    <!-- File Management Card -->
    <div class="sp-card">
        <header class="sp-card-header">
            <span class="sp-card-title"><i class="fas fa-volume-up"></i> <?php echo t('sound_alerts_your_alerts'); ?></span>
            <button class="sp-btn sp-btn-danger delete-selected-btn" disabled data-form="soundDeleteForm">
                <i class="fas fa-trash"></i> <span><?php echo t('sound_alerts_delete_selected'); ?></span>
            </button>
        </header>
        <div class="sp-card-body">
            <?php if (!empty($soundalert_files)): ?>
            <form action="" method="POST" id="soundDeleteForm" class="media-delete-form">
                <input type="hidden" name="media_type" value="sound_alert">
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th style="width:70px;text-align:center;"><?php echo t('sound_alerts_select'); ?></th>
                                <th style="text-align:center;"><?php echo t('sound_alerts_file_name'); ?></th>
                                <th style="text-align:center;"><?php echo t('sound_alerts_channel_point_reward'); ?></th>
                                <th style="width:80px;text-align:center;"><?php echo t('sound_alerts_action'); ?></th>
                                <th style="width:120px;text-align:center;"><?php echo t('sound_alerts_test_audio'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($soundalert_files as $file):
                                $current_reward_id = $soundAlertMappings[$file] ?? null;
                                $current_reward_title = $current_reward_id ? htmlspecialchars($rewardIdToTitle[$current_reward_id] ?? '') : t('sound_alerts_not_mapped');
                            ?>
                            <tr>
                                <td style="text-align:center;"><input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>"></td>
                                <td><?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?></td>
                                <td style="text-align:center;">
                                    <?php if ($current_reward_id): ?>
                                        <em><?php echo $current_reward_title; ?></em>
                                    <?php else: ?>
                                        <em><?php echo t('sound_alerts_not_mapped'); ?></em>
                                    <?php endif; ?>
                                    <form action="" method="POST" class="mapping-form" style="margin-top:0.5rem;">
                                        <input type="hidden" name="media_type" value="sound_alert_mapping">
                                        <input type="hidden" name="sound_file" value="<?php echo htmlspecialchars($file); ?>">
                                        <select name="reward_id" class="sp-select mapping-select" style="font-size:0.8rem;padding:0.35rem 2rem 0.35rem 0.6rem;">
                                            <?php if ($current_reward_id): ?>
                                                <option value=""><?php echo t('sound_alerts_remove_mapping'); ?></option>
                                            <?php endif; ?>
                                            <option value=""><?php echo t('sound_alerts_select_reward'); ?></option>
                                            <?php foreach ($channelPointRewards as $reward):
                                                $isMapped = (in_array($reward['reward_id'], $soundAlertMappings) || in_array($reward['reward_id'], $videoMappedRewards));
                                                $isCurrent = ($current_reward_id === $reward['reward_id']);
                                                if ($isMapped && !$isCurrent) continue;
                                            ?>
                                                <option value="<?php echo htmlspecialchars($reward['reward_id']); ?>"<?php if ($isCurrent) echo ' selected'; ?>>
                                                    <?php echo htmlspecialchars($reward['reward_title']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td style="text-align:center;">
                                    <button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="<?php echo htmlspecialchars($file); ?>" data-form="soundDeleteForm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                                <td style="text-align:center;">
                                    <button type="button" class="test-media sp-btn sp-btn-primary sp-btn-sm" data-event="SOUND_ALERT" data-param="sound" data-file="<?php echo htmlspecialchars($file); ?>">
                                        <i class="fas fa-play"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <?php else: ?>
                <div style="text-align:center;padding:3rem 0;">
                    <p style="color:var(--text-muted);font-size:1rem;"><?php echo t('sound_alerts_no_files_uploaded'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Video Alerts Tab -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="video-alerts" style="display:<?php echo $activeTab === 'video-alerts' ? 'block' : 'none'; ?>;">
    <!-- File Management Card -->
    <div class="sp-card">
        <header class="sp-card-header">
            <span class="sp-card-title"><i class="fas fa-film"></i> <?php echo t('video_alerts_your_alerts'); ?></span>
            <button class="sp-btn sp-btn-danger delete-selected-btn" disabled data-form="videoDeleteForm">
                <i class="fas fa-trash"></i> <span><?php echo t('video_alerts_delete_selected'); ?></span>
            </button>
        </header>
        <div class="sp-card-body">
            <?php if (!empty($videoalert_files)): ?>
            <form action="" method="POST" id="videoDeleteForm" class="media-delete-form">
                <input type="hidden" name="media_type" value="video_alert">
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th style="width:70px;text-align:center;"><?php echo t('video_alerts_select'); ?></th>
                                <th style="text-align:center;"><?php echo t('video_alerts_file_name'); ?></th>
                                <th style="text-align:center;"><?php echo t('video_alerts_channel_point_reward'); ?></th>
                                <th style="width:80px;text-align:center;"><?php echo t('video_alerts_action'); ?></th>
                                <th style="width:120px;text-align:center;"><?php echo t('video_alerts_test_video'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($videoalert_files as $file):
                                $current_reward_id = $videoAlertMappings[$file] ?? null;
                                $current_reward_title = $current_reward_id ? htmlspecialchars($rewardIdToTitle[$current_reward_id] ?? '') : t('video_alerts_not_mapped');
                            ?>
                            <tr>
                                <td style="text-align:center;"><input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>"></td>
                                <td><?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?></td>
                                <td style="text-align:center;">
                                    <?php if ($current_reward_id): ?>
                                        <em><?php echo $current_reward_title; ?></em>
                                    <?php else: ?>
                                        <em><?php echo t('video_alerts_not_mapped'); ?></em>
                                    <?php endif; ?>
                                    <form action="" method="POST" class="mapping-form" style="margin-top:0.5rem;">
                                        <input type="hidden" name="media_type" value="video_alert_mapping">
                                        <input type="hidden" name="video_file" value="<?php echo htmlspecialchars($file); ?>">
                                        <select name="reward_id" class="sp-select mapping-select" style="font-size:0.8rem;padding:0.35rem 2rem 0.35rem 0.6rem;">
                                            <?php if ($current_reward_id): ?>
                                                <option value=""><?php echo t('video_alerts_remove_mapping'); ?></option>
                                            <?php endif; ?>
                                            <option value=""><?php echo t('video_alerts_select_reward'); ?></option>
                                            <?php foreach ($channelPointRewards as $reward):
                                                $isMapped = (in_array($reward['reward_id'], $videoAlertMappings) || in_array($reward['reward_id'], $soundMappedRewards));
                                                $isCurrent = ($current_reward_id === $reward['reward_id']);
                                                if ($isMapped && !$isCurrent) continue;
                                            ?>
                                                <option value="<?php echo htmlspecialchars($reward['reward_id']); ?>"<?php if ($isCurrent) echo ' selected'; ?>>
                                                    <?php echo htmlspecialchars($reward['reward_title']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td style="text-align:center;">
                                    <button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="<?php echo htmlspecialchars($file); ?>" data-form="videoDeleteForm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                                <td style="text-align:center;">
                                    <button type="button" class="test-media sp-btn sp-btn-primary sp-btn-sm" data-event="VIDEO_ALERT" data-param="video" data-file="<?php echo htmlspecialchars($file); ?>">
                                        <i class="fas fa-play"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <?php else: ?>
                <div style="text-align:center;padding:3rem 0;">
                    <p style="color:var(--text-muted);font-size:1rem;"><?php echo t('video_alerts_no_files_uploaded'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Walk-ons Tab -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="walkons" style="display:<?php echo $activeTab === 'walkons' ? 'block' : 'none'; ?>;">
    <!-- File Management Card -->
    <div class="sp-card">
        <header class="sp-card-header">
            <span class="sp-card-title"><i class="fas fa-door-open"></i> <?php echo t('walkons_users_with_walkons'); ?></span>
            <button class="sp-btn sp-btn-danger delete-selected-btn" disabled data-form="walkonDeleteForm">
                <i class="fas fa-trash"></i> <span><?php echo t('walkons_delete_selected'); ?></span>
            </button>
        </header>
        <div class="sp-card-body">
            <?php if (!empty($walkon_files)): ?>
            <form action="" method="POST" id="walkonDeleteForm" class="media-delete-form">
                <input type="hidden" name="media_type" value="walkon">
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th style="width:70px;text-align:center;"><?php echo t('walkons_select'); ?></th>
                                <th style="text-align:center;"><?php echo t('walkons_file_name'); ?></th>
                                <th style="width:100px;text-align:center;"><?php echo t('walkons_action'); ?></th>
                                <th style="width:150px;text-align:center;"><?php echo t('walkons_test_audio'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($walkon_files as $file): ?>
                            <tr>
                                <td style="text-align:center;"><input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>"></td>
                                <td><?php echo htmlspecialchars(formatFileNameWithExt($file)); ?></td>
                                <td style="text-align:center;">
                                    <button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="<?php echo htmlspecialchars($file); ?>" data-form="walkonDeleteForm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                                <td style="text-align:center;">
                                    <button type="button" class="test-media sp-btn sp-btn-primary sp-btn-sm" data-event="WALKON" data-param="user" data-file="<?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?>">
                                        <i class="fas fa-play"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <?php else: ?>
                <div style="text-align:center;padding:3rem 0;">
                    <p style="color:var(--text-muted);font-size:1rem;"><?php echo t('walkons_no_files_uploaded'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Twitch Event Alerts Tab -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="twitch-events" style="display:<?php echo $activeTab === 'twitch-events' ? 'block' : 'none'; ?>;">
    <!-- File Management Card -->
    <div class="sp-card">
        <header class="sp-card-header">
            <span class="sp-card-title"><i class="fas fa-volume-up"></i> <?php echo t('modules_your_twitch_sound_alerts'); ?></span>
            <button class="sp-btn sp-btn-danger delete-selected-btn" disabled data-form="twitchDeleteForm">
                <i class="fas fa-trash"></i> <span><?php echo t('modules_delete_selected'); ?></span>
            </button>
        </header>
        <div class="sp-card-body">
            <?php if (!empty($soundalert_files)):
                $allEvents = ['Follow', 'Raid', 'Cheer', 'Subscription', 'Gift Subscription', 'Hype Train Start', 'Hype Train End'];
            ?>
            <form action="" method="POST" id="twitchDeleteForm" class="media-delete-form">
                <input type="hidden" name="media_type" value="twitch_event">
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th style="width:70px;text-align:center;"><?php echo t('modules_select'); ?></th>
                                <th style="text-align:center;"><?php echo t('modules_file_name'); ?></th>
                                <th style="text-align:center;"><?php echo t('modules_twitch_event'); ?></th>
                                <th style="width:80px;text-align:center;"><?php echo t('modules_action'); ?></th>
                                <th style="width:120px;text-align:center;"><?php echo t('modules_test_audio'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($soundalert_files as $file):
                                $current_mapped = $twitchSoundAlertMappings[$file] ?? null;
                                $mappedEvents = [];
                                foreach ($twitchSoundAlertMappings as $mappedFile => $mappedEvent) {
                                    if ($mappedFile !== $file && $mappedEvent) {
                                        $mappedEvents[] = $mappedEvent;
                                    }
                                }
                                $availableEvents = array_diff($allEvents, $mappedEvents);
                            ?>
                            <tr>
                                <td style="text-align:center;"><input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>"></td>
                                <td><?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?></td>
                                <td style="text-align:center;">
                                    <?php if ($current_mapped): ?>
                                        <em><?php echo t('modules_event_' . strtolower(str_replace(' ', '_', $current_mapped))); ?></em>
                                    <?php else: ?>
                                        <em><?php echo t('modules_not_mapped'); ?></em>
                                    <?php endif; ?>
                                    <form action="" method="POST" class="mapping-form" style="margin-top:0.5rem;">
                                        <input type="hidden" name="media_type" value="twitch_event_mapping">
                                        <input type="hidden" name="sound_file" value="<?php echo htmlspecialchars($file); ?>">
                                        <select name="twitch_alert_id" class="sp-select mapping-select">
                                            <?php if ($current_mapped): ?>
                                                <option value=""><?php echo t('modules_remove_mapping'); ?></option>
                                            <?php endif; ?>
                                            <option value=""><?php echo t('modules_select_event'); ?></option>
                                            <?php foreach ($allEvents as $evt):
                                                $isMapped = in_array($evt, $mappedEvents);
                                                $isCurrent = ($current_mapped === $evt);
                                                if ($isMapped && !$isCurrent) continue;
                                            ?>
                                                <option value="<?php echo htmlspecialchars($evt); ?>"<?php if ($isCurrent) echo ' selected'; ?>>
                                                    <?php echo t('modules_event_' . strtolower(str_replace(' ', '_', $evt))); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td style="text-align:center;">
                                    <button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="<?php echo htmlspecialchars($file); ?>" data-form="twitchDeleteForm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                                <td style="text-align:center;">
                                    <button type="button" class="test-media sp-btn sp-btn-primary sp-btn-sm" data-event="SOUND_ALERT" data-param="sound" data-file="twitch/<?php echo htmlspecialchars($file); ?>">
                                        <i class="fas fa-play"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <?php else: ?>
                <div style="text-align:center;padding:3rem 0;">
                    <p style="color:var(--text-muted);font-size:1rem;"><?php echo t('modules_no_sound_alert_files_uploaded'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<style>
.media-drop-zone {
    display:block;
    border:2px dashed var(--border);
    border-radius:var(--radius-lg);
    padding:1.5rem;
    text-align:center;
    cursor:pointer;
    background:var(--bg-input);
    transition:border-color var(--transition);
    color:var(--text-secondary);
}
.media-drop-zone:hover {
    border-color:var(--blue);
}
</style>
<script>
$(document).ready(function() {
    // Upload type selector for the unified upload card
    $('.upload-type-btn').on('click', function() {
        var type   = $(this).data('type');
        var accept = $(this).data('accept');
        var label  = $(this).data('label');
        $('.upload-type-btn').removeClass('sp-btn-primary').addClass('sp-btn-outline');
        $(this).removeClass('sp-btn-outline').addClass('sp-btn-primary');
        $('#unified-media-type').val(type);
        $('#unified-file-input').attr('accept', accept);
        $('#unified-upload-form .upload-btn-text').text('Upload ' + label);
        // Reset file selection display
        $('#unified-drop-zone .file-list-label').text('No files selected');
        $('#unified-file-input').val('');
    });

    // Tab switching
    $('.sp-tabs-nav li').on('click', function() {
        var tab = $(this).data('tab');
        $('.sp-tabs-nav li').removeClass('is-active');
        $(this).addClass('is-active');
        $('.tab-content').hide();
        $('#' + tab).show();
    });

    // Auto-dismiss status messages after 15 seconds
    if ($('.sp-notif').length) {
        setTimeout(function() {
            $('.sp-notif').fadeOut(500, function() {
                $(this).remove();
            });
        }, 15000);
    }

    // File input display update
    $(document).on('change', '.media-upload-form input[type="file"]', function() {
        let files = this.files;
        let label = $(this).closest('.media-drop-zone').find('.file-list-label');
        let names = [];
        for (let i = 0; i < files.length; i++) {
            names.push(files[i].name);
        }
        label.text(names.length ? names.join(', ') : label.data('default') || 'No files selected');
    });

    // AJAX upload with progress bar (unified for all tabs)
    $(document).on('submit', '.media-upload-form', function(e) {
        e.preventDefault();
        var form = $(this);
        var fileInput = form.find('input[type="file"]')[0];
        if (fileInput.files.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'No Files Selected',
                text: 'Please select at least one file to upload.',
                confirmButtonColor: '#3273dc'
            });
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
            url: '',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        var pct = Math.round((e.loaded / e.total) * 100);
                        progressBar.val(pct);
                        progressPercent.text(pct + '%');
                        if (pct < 100) {
                            statusText.html('<i class="fas fa-spinner fa-pulse"></i> Uploading... (' + pct + '%)');
                        } else {
                            statusText.html('<i class="fas fa-check-circle"></i> Processing files on server...');
                        }
                    }
                }, false);
                return xhr;
            },
            success: function() {
                statusText.html('<i class="fas fa-check-circle"></i> Upload completed successfully!');
                progressPercent.text('100%');
                setTimeout(function() { location.reload(); }, 1500);
            },
            error: function() {
                statusContainer.hide();
                uploadBtn.prop('disabled', false).removeClass('sp-btn-loading');
                uploadBtnText.text('Upload');
                Swal.fire({
                    icon: 'error',
                    title: 'Upload Failed',
                    text: 'An error occurred during upload. Please try again.',
                    confirmButtonColor: '#3273dc'
                });
            }
        });
    });

    // Mapping select change (AJAX submit)
    $(document).on('change', '.mapping-select', function() {
        var form = $(this).closest('form');
        $.post('', form.serialize(), function() {
            location.reload();
        });
    });

    // Checkbox monitoring for delete-selected buttons
    $(document).on('change', '.media-delete-form input[type="checkbox"]', function() {
        var form = $(this).closest('.media-delete-form');
        var formId = form.attr('id');
        var checked = form.find('input[name="delete_files[]"]:checked').length;
        $('.delete-selected-btn[data-form="' + formId + '"]').prop('disabled', checked < 2);
    });

    // Delete selected button
    $(document).on('click', '.delete-selected-btn', function() {
        var formId = $(this).data('form');
        var form = $('#' + formId);
        var checked = form.find('input[name="delete_files[]"]:checked');
        if (checked.length > 0) {
            Swal.fire({
                title: 'Delete Files?',
                text: 'Are you sure you want to delete the selected ' + checked.length + ' file(s)?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Yes, delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        }
    });

    // Single delete button
    $(document).on('click', '.delete-single', function() {
        var fileName = $(this).data('file');
        var formId = $(this).data('form');
        Swal.fire({
            title: 'Delete File?',
            text: 'Are you sure you want to delete "' + fileName + '"?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete',
            cancelButtonText: 'Cancel'
        }).then(function(result) {
            if (result.isConfirmed) {
                $('<input>').attr({
                    type: 'hidden',
                    name: 'delete_files[]',
                    value: fileName
                }).appendTo('#' + formId);
                $('#' + formId).submit();
            }
        });
    });
});

// Test media playback
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".test-media").forEach(function(button) {
        button.addEventListener("click", function() {
            var eventType = this.getAttribute("data-event");
            var paramName = this.getAttribute("data-param");
            var fileName = this.getAttribute("data-file");
            sendStreamEvent(eventType, paramName, fileName);
        });
    });
});

function sendStreamEvent(eventType, paramName, fileName) {
    var xhr = new XMLHttpRequest();
    var url = "notify_event.php";
    var params;
    if (eventType === "WALKON") {
        params = "event=" + eventType + "&user=" + encodeURIComponent(fileName) + "&channel=<?php echo $username; ?>&api_key=<?php echo $api_key; ?>";
    } else {
        params = "event=" + eventType + "&" + paramName + "=" + encodeURIComponent(fileName) + "&channel_name=<?php echo $username; ?>&api_key=<?php echo $api_key; ?>";
    }
    xhr.open("POST", url, true);
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var response = JSON.parse(xhr.responseText);
                if (response.success) {
                    console.log(eventType + " event for " + fileName + " sent successfully.");
                } else {
                    console.error("Error sending " + eventType + " event: " + response.message);
                }
            } catch (e) {
                console.error("Error parsing JSON response:", e);
            }
        } else if (xhr.readyState === 4) {
            console.error("Error sending " + eventType + " event: " + xhr.responseText);
        }
    };
    xhr.send(params);
}
</script>
<?php
$scripts = ob_get_clean();
include "layout.php";
?>
