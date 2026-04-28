<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
ini_set('max_execution_time', 300);

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
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
$activeTab = $_POST['media_type'] ?? 'sound-alerts';
// Normalize active tab from media_type POST values to tab IDs
$tabMap = [
    'media_upload' => 'sound-alerts',
    'sound_alert' => 'sound-alerts',
    'sound_alert_mapping' => 'sound-alerts',
    'video_alert' => 'video-alerts',
    'video_alert_mapping' => 'video-alerts',
    'walkon' => 'walkons',
    'twitch_event' => 'twitch-events',
    'twitch_event_mapping' => 'twitch-events',
    'alert_media' => 'alert-media',
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

// Fetch alert media references from twitch_alerts
$alertMediaFiles = [];
$alertMediaResult = $db->query("SELECT id, alert_category, variant_name, alert_image, alert_sound FROM twitch_alerts WHERE alert_image IS NOT NULL OR alert_sound IS NOT NULL");
if ($alertMediaResult) {
    while ($row = $alertMediaResult->fetch_assoc()) {
        if ($row['alert_image']) {
            $alertMediaFiles[$row['alert_image']][] = ['id' => $row['id'], 'category' => $row['alert_category'], 'variant' => $row['variant_name'], 'type' => 'image'];
        }
        if ($row['alert_sound']) {
            $alertMediaFiles[$row['alert_sound']][] = ['id' => $row['id'], 'category' => $row['alert_category'], 'variant' => $row['variant_name'], 'type' => 'sound'];
        }
    }
    $alertMediaResult->free();
}

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
        $targetDir = $media_path;
        $allowedExts = ['mp3', 'mp4'];
        $extLabel = 'MP3/MP4';
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
    // File deletion — all files live in the unified media library
    if (isset($_POST['delete_files']) && is_array($_POST['delete_files'])) {
        $deleteStatus = "";
        $db->begin_transaction();
        foreach ($_POST['delete_files'] as $file_to_delete) {
            $filename = basename($file_to_delete);
            $full_path = $media_path . '/' . $filename;
            if (is_file($full_path) && unlink($full_path)) {
                $deleteStatus .= "The file " . htmlspecialchars($filename) . " has been deleted.<br>";
                // Clean up all related mappings for this file
                $delSound = $db->prepare("DELETE FROM sound_alerts WHERE sound_mapping = ?");
                $delSound->bind_param('s', $filename);
                $delSound->execute();
                $delSound->close();
                $delVideo = $db->prepare("DELETE FROM video_alerts WHERE video_mapping = ?");
                $delVideo->bind_param('s', $filename);
                $delVideo->execute();
                $delVideo->close();
                $delTwitch = $db->prepare("DELETE FROM twitch_sound_alerts WHERE sound_mapping = ?");
                $delTwitch->bind_param('s', $filename);
                $delTwitch->execute();
                $delTwitch->close();
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
$all_media_files = is_dir($media_path) ? array_diff(scandir($media_path), array('.', '..')) : [];
$soundalert_files = array_filter($all_media_files, fn($f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'mp3');
$videoalert_files = array_filter($all_media_files, fn($f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'mp4');
$walkon_files = $all_media_files;

function formatFileNameWithExt($fileName) {
    $fileInfo = pathinfo($fileName);
    $name = basename($fileName, '.' . $fileInfo['extension']);
    $ext = strtoupper($fileInfo['extension']);
    return $name . " (" . $ext . ")";
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
                <small class="media-upload-hint"><i class="fas fa-info-circle"></i> Upload MP3 or MP4 files to your media library. Once uploaded, you can map files to Channel Point rewards, Twitch Events, or Walk-ons from the tabs below.</small>
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
<!-- Migrate to unified media library (Coming Soon) -->
<div class="sp-card media-migrate-card">
    <header class="sp-card-header media-migrate-header">
        <span class="sp-card-title"><i class="fas fa-arrow-circle-up"></i> Upgrade to Unified Media Library</span>
        <span class="media-migrate-badge">Coming Soon</span>
    </header>
    <div class="sp-card-body media-migrate-body">
        <h4>How the New System Works</h4>
        <p>BotOfTheSpecter is introducing a unified media library. Instead of keeping separate folders for Sound Alerts, Channel Point sounds, and Twitch Event sounds, <strong>all audio files live in one shared pool</strong>. A single MP3 can be mapped to a Channel Point reward <em>and</em> a Twitch Event simultaneously &mdash; no more duplicating files.</p>
        <ul>
            <li><strong>One upload, any trigger</strong> &mdash; upload a file once and assign it to any Channel Point or Twitch Event.</li>
            <li><strong>Unified library</strong> &mdash; all audio files live in one shared pool, no matter which trigger you assign them to.</li>
            <li><strong>Non-destructive migration</strong> &mdash; your existing files are <em>copied</em> into the new library; nothing is deleted from the old locations.</li>
            <li><strong>Beta Bot required</strong> &mdash; after migration, the Stable Bot will no longer trigger audio files. You must run the Beta Bot for alerts to work.</li>
        </ul>
        <div class="sp-alert sp-alert-info">
            <i class="fas fa-info-circle"></i> The migration tool will be available soon. No action is needed right now.
        </div>
        <button class="sp-btn sp-btn-warning media-migrate-btn-disabled" disabled>
            <i class="fas fa-clock"></i> Migrate to New Media Library &mdash; Coming Soon
        </button>
    </div>
</div>
<?php else: ?>
<div class="sp-alert sp-alert-success media-migrated-notice">
    <i class="fas fa-check-circle"></i> <strong>Using Unified Media Library</strong> &mdash; Your audio files are managed through the new shared library. Requires Beta Bot.
</div>
<?php endif; ?>
<!-- Tabs Navigation -->
<ul class="sp-tabs-nav media-tabs-nav">
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
    <li class="<?php echo $activeTab === 'alert-media' ? 'is-active' : ''; ?>" data-tab="alert-media">
        <a><i class="fas fa-bell"></i><span>Alert Media</span></a>
    </li>
</ul>
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
                    <table class="sp-table media-table">
                        <thead>
                            <tr>
                                <th class="col-select"><?php echo t('sound_alerts_select'); ?></th>
                                <th><?php echo t('sound_alerts_file_name'); ?></th>
                                <th><?php echo t('sound_alerts_channel_point_reward'); ?></th>
                                <th class="col-action"><?php echo t('sound_alerts_action'); ?></th>
                                <th class="col-test"><?php echo t('sound_alerts_test_audio'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($soundalert_files as $file):
                                $current_reward_id = $soundAlertMappings[$file] ?? null;
                                $current_reward_title = $current_reward_id ? htmlspecialchars($rewardIdToTitle[$current_reward_id] ?? '') : t('sound_alerts_not_mapped');
                            ?>
                            <tr>
                                <td><input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>"></td>
                                <td><?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?></td>
                                <td>
                                    <?php if ($current_reward_id): ?>
                                        <em><?php echo $current_reward_title; ?></em>
                                    <?php else: ?>
                                        <em><?php echo t('sound_alerts_not_mapped'); ?></em>
                                    <?php endif; ?>
                                    <form action="" method="POST" class="mapping-form media-mapping-form">
                                        <input type="hidden" name="media_type" value="sound_alert_mapping">
                                        <input type="hidden" name="sound_file" value="<?php echo htmlspecialchars($file); ?>">
                                        <select name="reward_id" class="sp-select mapping-select media-mapping-select">
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
                                <td>
                                    <button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="<?php echo htmlspecialchars($file); ?>" data-form="soundDeleteForm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                                <td>
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
                <div class="media-empty-state">
                    <p><?php echo t('sound_alerts_no_files_uploaded'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
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
                    <table class="sp-table media-table">
                        <thead>
                            <tr>
                                <th class="col-select"><?php echo t('video_alerts_select'); ?></th>
                                <th><?php echo t('video_alerts_file_name'); ?></th>
                                <th><?php echo t('video_alerts_channel_point_reward'); ?></th>
                                <th class="col-action"><?php echo t('video_alerts_action'); ?></th>
                                <th class="col-test"><?php echo t('video_alerts_test_video'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($videoalert_files as $file):
                                $current_reward_id = $videoAlertMappings[$file] ?? null;
                                $current_reward_title = $current_reward_id ? htmlspecialchars($rewardIdToTitle[$current_reward_id] ?? '') : t('video_alerts_not_mapped');
                            ?>
                            <tr>
                                <td><input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>"></td>
                                <td><?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?></td>
                                <td>
                                    <?php if ($current_reward_id): ?>
                                        <em><?php echo $current_reward_title; ?></em>
                                    <?php else: ?>
                                        <em><?php echo t('video_alerts_not_mapped'); ?></em>
                                    <?php endif; ?>
                                    <form action="" method="POST" class="mapping-form media-mapping-form">
                                        <input type="hidden" name="media_type" value="video_alert_mapping">
                                        <input type="hidden" name="video_file" value="<?php echo htmlspecialchars($file); ?>">
                                        <select name="reward_id" class="sp-select mapping-select media-mapping-select">
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
                                <td>
                                    <button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="<?php echo htmlspecialchars($file); ?>" data-form="videoDeleteForm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                                <td>
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
                <div class="media-empty-state">
                    <p><?php echo t('video_alerts_no_files_uploaded'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
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
                    <table class="sp-table media-table">
                        <thead>
                            <tr>
                                <th class="col-select"><?php echo t('walkons_select'); ?></th>
                                <th><?php echo t('walkons_file_name'); ?></th>
                                <th class="col-action-w"><?php echo t('walkons_action'); ?></th>
                                <th class="col-test-w"><?php echo t('walkons_test_audio'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($walkon_files as $file): ?>
                            <tr>
                                <td><input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>"></td>
                                <td><?php echo htmlspecialchars(formatFileNameWithExt($file)); ?></td>
                                <td>
                                    <button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="<?php echo htmlspecialchars($file); ?>" data-form="walkonDeleteForm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                                <td>
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
                <div class="media-empty-state">
                    <p><?php echo t('walkons_no_files_uploaded'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
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
                    <table class="sp-table media-table">
                        <thead>
                            <tr>
                                <th class="col-select"><?php echo t('modules_select'); ?></th>
                                <th><?php echo t('modules_file_name'); ?></th>
                                <th><?php echo t('modules_twitch_event'); ?></th>
                                <th class="col-action"><?php echo t('modules_action'); ?></th>
                                <th class="col-test"><?php echo t('modules_test_audio'); ?></th>
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
                                <td><input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>"></td>
                                <td><?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?></td>
                                <td>
                                    <?php if ($current_mapped): ?>
                                        <em><?php echo t('modules_event_' . strtolower(str_replace(' ', '_', $current_mapped))); ?></em>
                                    <?php else: ?>
                                        <em><?php echo t('modules_not_mapped'); ?></em>
                                    <?php endif; ?>
                                    <form action="" method="POST" class="mapping-form media-mapping-form">
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
                                <td>
                                    <button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="<?php echo htmlspecialchars($file); ?>" data-form="twitchDeleteForm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                                <td>
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
                <div class="media-empty-state">
                    <p><?php echo t('modules_no_sound_alert_files_uploaded'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<div class="tab-content" id="alert-media" style="display:<?php echo $activeTab === 'alert-media' ? 'block' : 'none'; ?>;">
    <div class="sp-card">
        <header class="sp-card-header">
            <span class="sp-card-title"><i class="fas fa-bell"></i> Alert Media</span>
            <button class="sp-btn sp-btn-danger delete-selected-btn" disabled data-form="alertMediaDeleteForm">
                <i class="fas fa-trash"></i> <span>Delete Selected</span>
            </button>
        </header>
        <div class="sp-card-body">
            <?php if (!empty($soundalert_files)): ?>
            <form action="" method="POST" id="alertMediaDeleteForm" class="media-delete-form">
                <input type="hidden" name="media_type" value="alert_media">
                <div class="sp-table-wrap">
                    <table class="sp-table media-table">
                        <thead>
                            <tr>
                                <th class="col-select">Select</th>
                                <th>File Name</th>
                                <th>Used By Alerts</th>
                                <th class="col-action">Action</th>
                                <th class="col-test">Test Audio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($soundalert_files as $file):
                                $usages = $alertMediaFiles[$file] ?? [];
                            ?>
                            <tr>
                                <td><input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>"></td>
                                <td><?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?></td>
                                <td>
                                    <?php if (!empty($usages)): ?>
                                        <?php foreach ($usages as $usage): ?>
                                            <div>
                                                <em><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $usage['category']))); ?></em>
                                                &mdash; <?php echo htmlspecialchars($usage['variant']); ?>
                                                (<?php echo $usage['type']; ?>)
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <em>Not used in any alert</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="<?php echo htmlspecialchars($file); ?>" data-form="alertMediaDeleteForm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                                <td>
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
                <div class="media-empty-state">
                    <p>No MP3 files uploaded yet. Upload files using the upload form above.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
$(document).ready(function() {
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
            success: function(response) {
                var data = typeof response === 'string' ? JSON.parse(response) : response;
                if (data && !data.success) {
                    statusContainer.hide();
                    uploadBtn.prop('disabled', false).removeClass('sp-btn-loading');
                    uploadBtnText.text('Upload Media (MP3/MP4)');
                    Swal.fire({
                        icon: 'error',
                        title: 'Upload Failed',
                        html: data.status || 'An error occurred during upload.',
                        confirmButtonColor: '#3273dc'
                    });
                    return;
                }
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
