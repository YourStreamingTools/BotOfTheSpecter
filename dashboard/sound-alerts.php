<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
ini_set('max_execution_time', 300);

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('sound_alerts_page_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

function sound_alerts_collect_list($db, $soundalert_path) {
    $soundAlertMappings = [];
    $getSoundAlerts = $db->prepare("SELECT sound_mapping, reward_id FROM sound_alerts");
    $getSoundAlerts->execute();
    $getSoundAlerts->bind_result($sound_mapping, $reward_id);
    while ($getSoundAlerts->fetch()) {
        $soundAlertMappings[$sound_mapping] = $reward_id;
    }
    $getSoundAlerts->close();

    $videoMappedRewards = [];
    $getVideoAlertsForMapping = $db->prepare("SELECT DISTINCT reward_id FROM video_alerts");
    $getVideoAlertsForMapping->execute();
    $getVideoAlertsForMapping->bind_result($video_reward_id);
    while ($getVideoAlertsForMapping->fetch()) {
        $videoMappedRewards[] = $video_reward_id;
    }
    $getVideoAlertsForMapping->close();

    $channelPointRewards = [];
    $rewardStmt = $db->query("SELECT reward_id, reward_title FROM channel_point_rewards ORDER BY CONVERT(reward_cost, UNSIGNED) ASC");
    if ($rewardStmt) {
        $channelPointRewards = $rewardStmt->fetch_all(MYSQLI_ASSOC);
    }

    $soundalert_files = [];
    if (is_string($soundalert_path) && $soundalert_path !== '' && is_dir($soundalert_path)) {
        $soundalert_files = array_values(array_diff(scandir($soundalert_path), array('.', '..', 'twitch')));
    }

    return [
        'files' => $soundalert_files,
        'mappings' => (object) $soundAlertMappings,
        'video_mapped_rewards' => array_values($videoMappedRewards),
        'rewards' => $channelPointRewards,
    ];
}

// List endpoint first so the browser can paint skeletons, then fetch files + rewards + storage.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        include 'includes/storage_used.php';
        $list = sound_alerts_collect_list($db, $soundalert_path ?? '');
        echo json_encode([
            'success' => true,
            'storage_used' => (int) $current_storage_used,
            'max_storage' => (int) $max_storage_size,
            'storage_percentage' => (float) $storage_percentage,
            'files' => $list['files'],
            'mappings' => $list['mappings'],
            'video_mapped_rewards' => $list['video_mapped_rewards'],
            'rewards' => $list['rewards'],
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Define empty variables
$status = '';

// Handle channel point reward mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sound_file'], $_POST['reward_id'])) {
    $status = ""; // Initialize $status
    $soundFile = $_POST['sound_file'];
    $rewardId = $_POST['reward_id'];
    $soundFile = htmlspecialchars($soundFile);
    $db->begin_transaction();
    // Check if a mapping already exists for this sound file
    $checkExisting = $db->prepare("SELECT 1 FROM sound_alerts WHERE sound_mapping = ?");
    $checkExisting->bind_param('s', $soundFile);
    $checkExisting->execute();
    $checkExisting->store_result();
    if ($checkExisting->num_rows > 0) {
        // Update existing mapping
        if ($rewardId) {
            $updateMapping = $db->prepare("UPDATE sound_alerts SET reward_id = ? WHERE sound_mapping = ?");
            $updateMapping->bind_param('ss', $rewardId, $soundFile);
            if (!$updateMapping->execute()) {
                $status .= t('sound_alerts_status_update_failed', ['file' => $soundFile, 'error' => $updateMapping->error]) . "<br>";
            } else {
                $status .= t('sound_alerts_status_update_success', ['file' => $soundFile]) . "<br>";
            }
            $updateMapping->close();
        } else {
            // Delete the mapping if no reward is selected (Remove Mapping option)
            $deleteMapping = $db->prepare("DELETE FROM sound_alerts WHERE sound_mapping = ?");
            $deleteMapping->bind_param('s', $soundFile);
            if (!$deleteMapping->execute()) {
                $status .= t('sound_alerts_status_remove_failed', ['file' => $soundFile, 'error' => $deleteMapping->error]) . "<br>";
            } else {
                $status .= t('sound_alerts_status_remove_success', ['file' => $soundFile]) . "<br>";
            }
            $deleteMapping->close();
        }
    } else {
        // Create a new mapping if it doesn't exist
        if ($rewardId) {
            $insertMapping = $db->prepare("INSERT INTO sound_alerts (sound_mapping, reward_id) VALUES (?, ?)");
            $insertMapping->bind_param('ss', $soundFile, $rewardId);
            if (!$insertMapping->execute()) {
                $status .= t('sound_alerts_status_create_failed', ['file' => $soundFile, 'error' => $insertMapping->error]) . "<br>";
            } else {
                $status .= t('sound_alerts_status_create_success', ['file' => $soundFile]) . "<br>";
            }
            $insertMapping->close();
        }
    }
    $checkExisting->close();
    // Commit transaction
    $db->commit();
}

// Disk scan only when an upload or delete actually needs paths / quota.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_FILES["filesToUpload"]) || isset($_POST['delete_files']))) {
    include 'includes/storage_used.php';
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES["filesToUpload"])) {
    $status = "";
    foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
        $fileSize = $_FILES["filesToUpload"]["size"][$key];
        if ($current_storage_used + $fileSize > $max_storage_size) {
            $status .= t('sound_alerts_status_upload_storage_exceeded', ['file' => htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key]))]) . "<br>";
            continue;
        }
        $targetFile = $soundalert_path . '/' . basename($_FILES["filesToUpload"]["name"][$key]);
        $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
        if ($fileType != "mp3") {
            $status .= t('sound_alerts_status_upload_mp3_only', ['file' => htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key]))]) . "<br>";
            continue;
        }
        if (move_uploaded_file($tmp_name, $targetFile)) {
            $current_storage_used += $fileSize;
            $status .= t('sound_alerts_status_upload_success', ['file' => htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key]))]) . "<br>";
        } else {
            $status .= t('sound_alerts_status_upload_error', ['file' => htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key]))]) . "<br>";
        }
    }
}

// Handle file deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_files'])) {
    $status = "";
    $db->begin_transaction();
    foreach ($_POST['delete_files'] as $file_to_delete) {
        $filename = basename($file_to_delete);
        $full_path = $soundalert_path . '/' . $filename;
        // First delete the physical file
        if (is_file($full_path) && unlink($full_path)) {
            $status .= t('sound_alerts_status_delete_success', ['file' => htmlspecialchars($filename)]) . "<br>";
            // Now delete any mapping for this file from the database
            $deleteMapping = $db->prepare("DELETE FROM sound_alerts WHERE sound_mapping = ?");
            $deleteMapping->bind_param('s', $filename);
            if ($deleteMapping->execute()) {
                if ($deleteMapping->affected_rows > 0) {
                    $status .= t('sound_alerts_status_delete_mapping_removed', ['file' => htmlspecialchars($filename)]) . "<br>";
                }
            } else {
                $status .= t('sound_alerts_status_delete_mapping_warning', ['file' => htmlspecialchars($filename)]) . "<br>";
            }
            $deleteMapping->close();
        } else {
            $status .= t('sound_alerts_status_delete_failed', ['file' => htmlspecialchars($filename)]) . "<br>";
        }
    }
    $db->commit(); // Commit all database changes
}

ob_start();
?>
<!-- How-To Info Box -->
    <div class="sp-alert sp-alert-danger" style="margin-bottom:1.5rem;">
        <div style="display:flex;align-items:flex-start;gap:1rem;">
            <span style="font-size:1.5rem;flex-shrink:0;"><i class="fas fa-bell"></i></span>
            <div>
                <p style="font-weight:700;margin-bottom:0.5rem;"><?php echo t('sound_alerts_setup_title'); ?></p>
                <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:0.3rem;">
                    <li><i class="fas fa-upload" style="margin-right:0.4rem;"></i> <?php echo t('sound_alerts_upload_instruction'); ?></li>
                    <li><i class="fab fa-twitch" style="margin-right:0.4rem;"></i> <?php echo t('sound_alerts_rewards_instruction'); ?></li>
                    <li><i class="fas fa-play-circle" style="margin-right:0.4rem;"></i> <?php echo t('sound_alerts_play_instruction'); ?></li>
                    <li><i class="fas fa-headphones" style="margin-right:0.4rem;"></i> <?php echo t('sound_alerts_overlay_instruction'); ?></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Upload Card -->
    <div class="sp-card">
        <header class="sp-card-header">
            <span class="sp-card-title">
                <i class="fas fa-upload"></i>
                <?php echo t('sound_alerts_upload_title'); ?>
            </span>
        </header>
        <div class="sp-card-body">
            <!-- Storage Usage Info -->
            <div class="sp-alert sp-alert-info" id="soundAlertsStorageHost" style="margin-bottom:1rem;" aria-busy="true">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                    <span><i class="fas fa-database" style="margin-right:0.4rem;"></i> <strong><?php echo t('alerts_storage_usage'); ?>:</strong></span>
                    <span id="soundAlertsStorageText"><span class="sp-skeleton-line w-40" aria-hidden="true"></span></span>
                </div>
                <progress class="progress" id="soundAlertsStorageProgress" value="0" max="100" style="width:100%;"></progress>
            </div>
            <?php if (!empty($status)) : ?>
                <div class="sp-alert sp-alert-info sp-notif" style="margin-bottom:1rem;">
                    <?php echo $status; ?>
                </div>
            <?php endif; ?>
            <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
                <div class="sp-form-group">
                    <label for="filesToUpload" style="display:block;border:2px dashed var(--border);border-radius:var(--radius-lg);padding:1.5rem;text-align:center;cursor:pointer;background:var(--bg-input);transition:border-color var(--transition);color:var(--text-secondary);">
                        <i class="fas fa-cloud-upload-alt" style="font-size:2rem;margin-bottom:0.5rem;display:block;"></i>
                        <span id="file-list"><?php echo t('sound_alerts_no_files_selected'); ?></span>
                        <div style="margin-top:0.5rem;font-size:0.8rem;color:var(--text-muted);"><?php echo t('sound_alerts_choose_files'); ?></div>
                        <input type="file" name="filesToUpload[]" id="filesToUpload" multiple accept=".mp3" style="display:none;">
                    </label>
                </div>
                <!-- Upload Status Container -->
                <div id="uploadStatusContainer" style="display:none;margin-bottom:1rem;">
                    <div class="sp-alert sp-alert-info">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                            <strong id="uploadStatusText"><?php echo t('sound_alerts_preparing_upload'); ?></strong>
                            <span id="uploadProgressPercent" style="font-weight:600;">0%</span>
                        </div>
                        <progress class="progress" id="uploadProgress" value="0" max="100" style="width:100%;">0%</progress>
                    </div>
                </div>
                <button class="sp-btn sp-btn-primary" type="submit" name="submit" id="uploadBtn" style="width:100%;font-size:1.1rem;">
                    <i class="fas fa-upload"></i>
                    <span id="uploadBtnText"><?php echo t('sound_alerts_upload_btn'); ?></span>
                </button>
            </form>
        </div>
    </div>

    <!-- File Management Card -->
    <div class="sp-card">
        <header class="sp-card-header">
            <span class="sp-card-title">
                <i class="fas fa-volume-up"></i>
                <?php echo t('sound_alerts_your_alerts'); ?>
            </span>
            <button class="sp-btn sp-btn-danger" id="deleteSelectedBtn" disabled>
                <i class="fas fa-trash"></i>
                <span><?php echo t('sound_alerts_delete_selected'); ?></span>
            </button>
        </header>
        <div class="sp-card-body" id="soundAlertsListHost" aria-busy="true">
            <form action="" method="POST" id="deleteForm">
                <div class="sp-table-wrap">
                    <table class="sp-table" id="soundAlertsTable">
                        <thead>
                            <tr>
                                <th style="width:70px;text-align:center;"><?php echo t('sound_alerts_select'); ?></th>
                                <th style="text-align:center;"><?php echo t('sound_alerts_file_name'); ?></th>
                                <th style="text-align:center;"><?php echo t('sound_alerts_channel_point_reward'); ?></th>
                                <th style="width:80px;text-align:center;"><?php echo t('sound_alerts_action'); ?></th>
                                <th style="width:120px;text-align:center;"><?php echo t('sound_alerts_test_audio'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="soundAlertsTableBody" aria-busy="true">
                            <?php for ($sk = 0; $sk < 5; $sk++): ?>
                            <tr aria-hidden="true">
                                <td style="text-align:center;"><span class="sp-skeleton-badge"></span></td>
                                <td><span class="sp-skeleton-line w-70"></span></td>
                                <td style="text-align:center;"><span class="sp-skeleton-line w-80"></span></td>
                                <td style="text-align:center;"><span class="sp-skeleton-badge"></span></td>
                                <td style="text-align:center;"><span class="sp-skeleton-badge"></span></td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
                <button type="submit" value="Delete Selected" class="sp-btn sp-btn-danger" name="submit_delete" style="display:none;margin-top:0.75rem;">
                    <i class="fas fa-trash"></i>
                    <span><?php echo t('sound_alerts_delete_selected'); ?></span>
                </button>
            </form>
            <div id="soundAlertsEmpty" style="display:none;text-align:center;padding:3rem 0;">
                <p style="color:var(--text-muted);font-size:1rem;"><?php echo t('sound_alerts_no_files_uploaded'); ?></p>
            </div>
        </div>
    </div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
const SA_I18N = {
    notMapped: <?php echo json_encode(t('sound_alerts_not_mapped')); ?>,
    removeMapping: <?php echo json_encode(t('sound_alerts_remove_mapping')); ?>,
    selectReward: <?php echo json_encode(t('sound_alerts_select_reward')); ?>,
    noFilesSelected: <?php echo json_encode(t('sound_alerts_no_files_selected')); ?>,
    noFilesSelectedTitle: <?php echo json_encode(t('sound_alerts_no_files_selected_title')); ?>,
    noFilesSelectedText: <?php echo json_encode(t('sound_alerts_no_files_selected_text')); ?>,
    uploadingFiles: <?php echo json_encode(t('sound_alerts_uploading_files')); ?>,
    uploading: <?php echo json_encode(t('sound_alerts_uploading')); ?>,
    uploadingPercent: <?php echo json_encode(t('sound_alerts_uploading_percent')); ?>,
    processingFiles: <?php echo json_encode(t('sound_alerts_processing_files')); ?>,
    uploadCompleted: <?php echo json_encode(t('sound_alerts_upload_completed')); ?>,
    uploadBtn: <?php echo json_encode(t('sound_alerts_upload_btn')); ?>,
    uploadFailedTitle: <?php echo json_encode(t('sound_alerts_upload_failed_title')); ?>,
    uploadFailedText: <?php echo json_encode(t('sound_alerts_upload_failed_text')); ?>,
    deleteTitle: <?php echo json_encode(t('sound_alerts_delete_file_title')); ?>,
    deleteConfirm: <?php echo json_encode(t('sound_alerts_delete_file_confirm')); ?>,
    deleteSelectedConfirm: <?php echo json_encode(t('sound_alerts_delete_selected_confirm')); ?>,
    deleteConfirmBtn: <?php echo json_encode(t('sound_alerts_delete_file_confirm_btn')); ?>,
    deleteCancelBtn: <?php echo json_encode(t('sound_alerts_delete_file_cancel_btn')); ?>
};

function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function fileDisplayName(fileName) {
    var value = String(fileName == null ? '' : fileName);
    return value.replace(/\.mp3$/i, '');
}

function applySoundAlertsStorageBar(data) {
    if (!data || typeof data.storage_used !== 'number' || typeof data.max_storage !== 'number') return;
    var usedMb = (data.storage_used / 1024 / 1024).toFixed(2);
    var maxMb = (data.max_storage / 1024 / 1024).toFixed(2);
    var pct = typeof data.storage_percentage === 'number'
        ? data.storage_percentage
        : ((data.max_storage > 0) ? (data.storage_used / data.max_storage) * 100 : 0);
    var textEl = document.getElementById('soundAlertsStorageText');
    var progEl = document.getElementById('soundAlertsStorageProgress');
    var hostEl = document.getElementById('soundAlertsStorageHost');
    if (textEl) textEl.textContent = usedMb + 'MB / ' + maxMb + 'MB (' + Number(pct).toFixed(2) + '%)';
    if (progEl) progEl.value = pct;
    if (hostEl) hostEl.setAttribute('aria-busy', 'false');
}

function rewardOptionsHtml(file, mappings, videoMapped, rewards) {
    var currentRewardId = mappings && mappings[file] ? String(mappings[file]) : '';
    var mappedIds = {};
    if (mappings && typeof mappings === 'object') {
        Object.keys(mappings).forEach(function(key) {
            if (mappings[key]) mappedIds[String(mappings[key])] = true;
        });
    }
    var html = '';
    if (currentRewardId) {
        html += '<option value="">' + escapeHtml(SA_I18N.removeMapping) + '</option>';
    }
    html += '<option value="">' + escapeHtml(SA_I18N.selectReward) + '</option>';
    (rewards || []).forEach(function(reward) {
        var rewardId = reward && reward.reward_id != null ? String(reward.reward_id) : '';
        if (!rewardId) return;
        var isCurrent = (currentRewardId === rewardId);
        var isMapped = !!mappedIds[rewardId] || videoMapped[rewardId];
        if (isMapped && !isCurrent) return;
        html += '<option value="' + escapeHtml(rewardId) + '"' + (isCurrent ? ' selected' : '') + '>' +
            escapeHtml(reward.reward_title || '') + '</option>';
    });
    return html;
}

function renderSoundAlertsTable(data) {
    var tbody = document.getElementById('soundAlertsTableBody');
    var host = document.getElementById('soundAlertsListHost');
    var form = document.getElementById('deleteForm');
    var emptyEl = document.getElementById('soundAlertsEmpty');
    if (!tbody) return;
    var files = Array.isArray(data.files) ? data.files : [];
    var mappings = (data.mappings && !Array.isArray(data.mappings)) ? data.mappings : {};
    var videoMapped = {};
    (Array.isArray(data.video_mapped_rewards) ? data.video_mapped_rewards : []).forEach(function(id) {
        if (id) videoMapped[String(id)] = true;
    });
    var rewards = Array.isArray(data.rewards) ? data.rewards : [];
    if (files.length === 0) {
        tbody.innerHTML = '';
        if (form) form.style.display = 'none';
        if (emptyEl) emptyEl.style.display = '';
    } else {
        if (form) form.style.display = '';
        if (emptyEl) emptyEl.style.display = 'none';
        var rows = '';
        files.forEach(function(file) {
            var safeFile = escapeHtml(file);
            var currentRewardId = mappings[file] ? String(mappings[file]) : '';
            var currentTitle = SA_I18N.notMapped;
            if (currentRewardId) {
                var match = rewards.find(function(reward) { return String(reward.reward_id) === currentRewardId; });
                currentTitle = match && match.reward_title ? match.reward_title : SA_I18N.notMapped;
            }
            rows += '<tr>' +
                '<td style="text-align:center;"><input type="checkbox" name="delete_files[]" value="' + safeFile + '"></td>' +
                '<td>' + escapeHtml(fileDisplayName(file)) + '</td>' +
                '<td style="text-align:center;">' +
                    '<em>' + escapeHtml(currentTitle) + '</em>' +
                    '<div class="mapping-form" style="margin-top:0.5rem;">' +
                        '<select name="reward_id" class="sp-select mapping-select" data-file="' + safeFile + '" style="font-size:0.8rem;padding:0.35rem 2rem 0.35rem 0.6rem;">' +
                            rewardOptionsHtml(file, mappings, videoMapped, rewards) +
                        '</select>' +
                    '</div>' +
                '</td>' +
                '<td style="text-align:center;">' +
                    '<button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="' + safeFile + '">' +
                        '<i class="fas fa-trash"></i>' +
                    '</button>' +
                '</td>' +
                '<td style="text-align:center;">' +
                    '<button type="button" class="test-sound sp-btn sp-btn-primary sp-btn-sm" data-file="' + safeFile + '">' +
                        '<i class="fas fa-play"></i>' +
                    '</button>' +
                '</td>' +
            '</tr>';
        });
        tbody.innerHTML = rows;
    }
    tbody.setAttribute('aria-busy', 'false');
    if (host) host.setAttribute('aria-busy', 'false');
    $('#deleteSelectedBtn').prop('disabled', true);
}

function finishSoundAlertsListError() {
    var tbody = document.getElementById('soundAlertsTableBody');
    var host = document.getElementById('soundAlertsListHost');
    var storageHost = document.getElementById('soundAlertsStorageHost');
    if (tbody) {
        tbody.innerHTML = '';
        tbody.setAttribute('aria-busy', 'false');
    }
    if (host) host.setAttribute('aria-busy', 'false');
    if (storageHost) storageHost.setAttribute('aria-busy', 'false');
}

function loadSoundAlertsList() {
    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.success) {
                finishSoundAlertsListError();
                return;
            }
            applySoundAlertsStorageBar(data);
            renderSoundAlertsTable(data);
        })
        .catch(finishSoundAlertsListError);
}

// Function to send a stream event
function sendStreamEvent(eventType, fileName) {
    const xhr = new XMLHttpRequest();
    const url = "/api/notify_event.php";
    const params = `event=${eventType}&sound=${encodeURIComponent(fileName)}&channel_name=<?php echo $username; ?>&api_key=<?php echo $api_key; ?>`;
    xhr.open("POST", url, true);
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    console.log(`${eventType} event for ${fileName} sent successfully.`);
                } else {
                    console.error(`Error sending ${eventType} event: ${response.message}`);
                }
            } catch (e) {
                console.error("Error parsing JSON response:", e);
                console.error("Response:", xhr.responseText);
            }
        } else if (xhr.readyState === 4) {
            console.error(`Error sending ${eventType} event: ${xhr.responseText}`);
        }
    };
    xhr.send(params);
}

$(document).ready(function() {
    loadSoundAlertsList();

    // Auto-dismiss status messages after 15 seconds
    if ($('.sp-alert.sp-notif').length) {
        setTimeout(function() {
            $('.sp-alert.sp-notif').fadeOut(500, function() {
                $(this).remove();
            });
        }, 15000);
    }

    // Handle select all checkbox
    $('#selectAll').on('change', function() {
        $('input[name="delete_files[]"]').prop('checked', this.checked);
        var checkedBoxes = $('input[name="delete_files[]"]:checked').length;
        $('#deleteSelectedBtn').prop('disabled', checkedBoxes < 2);
    });
    // Handle delete selected button
    $('#deleteSelectedBtn').on('click', function() {
        var checkedBoxes = $('input[name="delete_files[]"]:checked');
        if (checkedBoxes.length > 0) {
            Swal.fire({
                title: SA_I18N.deleteTitle,
                text: SA_I18N.deleteSelectedConfirm.replace(':count', checkedBoxes.length),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: SA_I18N.deleteConfirmBtn,
                cancelButtonText: SA_I18N.deleteCancelBtn
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#deleteForm').submit();
                }
            });
        }
    });
    // Monitor checkbox changes to enable/disable delete button
    $(document).on('change', 'input[name="delete_files[]"]', function() {
        var checkedBoxes = $('input[name="delete_files[]"]:checked').length;
        $('#deleteSelectedBtn').prop('disabled', checkedBoxes < 2);
    });
    // Update file name display
    $('#filesToUpload').on('change', function() {
        let files = this.files;
        let fileNames = [];
        for (let i = 0; i < files.length; i++) {
            fileNames.push(files[i].name);
        }
        $('#file-list').text(fileNames.length ? fileNames.join(', ') : SA_I18N.noFilesSelected);
    });
    // Mapping select boxes (delegated — rows hydrate after ajax_action=list)
    $(document).on('change', '.mapping-select', function() {
        var soundFile = $(this).data('file');
        $.post('', { sound_file: soundFile, reward_id: $(this).val() }, function() {
            location.reload();
        });
    });
    // AJAX upload with progress bar
    $('#uploadForm').on('submit', function(e) {
        e.preventDefault();
        var files = $('#filesToUpload')[0].files;
        if (files.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: SA_I18N.noFilesSelectedTitle,
                text: SA_I18N.noFilesSelectedText,
                confirmButtonColor: '#3273dc'
            });
            return;
        }
        let formData = new FormData(this);
        // Show upload status and update UI
        $('#uploadStatusContainer').show();
        $('#uploadStatusText').html('<i class="fas fa-spinner fa-pulse"></i> ' + SA_I18N.uploadingFiles.replace(':count', files.length));
        $('#uploadProgressPercent').text('0%');
        $('#uploadProgress').val(0);
        // Update button state
        $('#uploadBtn').prop('disabled', true).addClass('sp-btn-loading');
        $('#uploadBtnText').text(SA_I18N.uploading);
        $.ajax({
            url: '',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            xhr: function() {
                let xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        let percentComplete = Math.round((e.loaded / e.total) * 100);
                        $('#uploadProgress').val(percentComplete);
                        $('#uploadProgressPercent').text(percentComplete + '%');

                        if (percentComplete < 100) {
                            $('#uploadStatusText').html('<i class="fas fa-spinner fa-pulse"></i> ' + SA_I18N.uploadingPercent.replace(':percent', percentComplete));
                        } else {
                            $('#uploadStatusText').html('<i class="fas fa-check-circle"></i> ' + SA_I18N.processingFiles);
                        }
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                $('#uploadStatusText').html('<i class="fas fa-check-circle"></i> ' + SA_I18N.uploadCompleted);
                $('#uploadProgressPercent').text('100%');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('Upload failed: ' + textStatus + ' - ' + errorThrown);
                $('#uploadStatusContainer').hide();
                $('#uploadBtn').prop('disabled', false).removeClass('sp-btn-loading');
                $('#uploadBtnText').text(SA_I18N.uploadBtn);
                Swal.fire({
                    icon: 'error',
                    title: SA_I18N.uploadFailedTitle,
                    text: SA_I18N.uploadFailedText,
                    confirmButtonColor: '#3273dc'
                });
            }
        });
    });
    // Single delete button with SweetAlert2
    $(document).on('click', '.delete-single', function() {
        let fileName = $(this).data('file');
        Swal.fire({
            title: SA_I18N.deleteTitle,
            text: SA_I18N.deleteConfirm.replace(':file', fileName),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: SA_I18N.deleteConfirmBtn,
            cancelButtonText: SA_I18N.deleteCancelBtn
        }).then((result) => {
            if (result.isConfirmed) {
                $('<input>').attr({
                    type: 'hidden',
                    name: 'delete_files[]',
                    value: fileName
                }).appendTo('#deleteForm');
                $('#deleteForm').submit();
            }
        });
    });
    $(document).on('click', '.test-sound', function() {
        sendStreamEvent("SOUND_ALERT", $(this).data('file'));
    });
});
</script>
<?php
$scripts = ob_get_clean();
include "layout.php";
?>
