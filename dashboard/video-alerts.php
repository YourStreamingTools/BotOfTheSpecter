<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
ini_set('max_execution_time', 300);

require_once '/var/www/lib/require_auth.php';

$pageTitle = t('video_alerts_page_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

function translateUploadError($code) {
    switch ($code) {
        case UPLOAD_ERR_OK: return t('video_alerts_upload_error_ok');
        case UPLOAD_ERR_INI_SIZE: return t('video_alerts_upload_error_ini_size');
        case UPLOAD_ERR_FORM_SIZE: return t('video_alerts_upload_error_form_size');
        case UPLOAD_ERR_PARTIAL: return t('video_alerts_upload_error_partial');
        case UPLOAD_ERR_NO_FILE: return t('video_alerts_upload_error_no_file');
        case UPLOAD_ERR_NO_TMP_DIR: return t('video_alerts_upload_error_no_tmp_dir');
        case UPLOAD_ERR_CANT_WRITE: return t('video_alerts_upload_error_cant_write');
        case UPLOAD_ERR_EXTENSION: return t('video_alerts_upload_error_extension');
        default: return t('video_alerts_upload_error_unknown');
    }
}

function video_alerts_list_payload($db) {
    include 'includes/storage_used.php';

    $videoAlertMappings = [];
    if ($result = $db->query("SELECT video_mapping, reward_id FROM video_alerts")) {
        while ($row = $result->fetch_assoc()) {
            $videoAlertMappings[$row['video_mapping']] = $row['reward_id'];
        }
        $result->free();
    }

    $soundMappedRewards = [];
    if ($result = $db->query("SELECT DISTINCT reward_id FROM sound_alerts")) {
        while ($row = $result->fetch_assoc()) {
            $soundMappedRewards[] = $row['reward_id'];
        }
        $result->free();
    }

    $rewards = [];
    if ($result = $db->query("SELECT reward_id, reward_title FROM channel_point_rewards ORDER BY CONVERT(reward_cost, UNSIGNED) ASC")) {
        while ($row = $result->fetch_assoc()) {
            $rewards[] = [
                'reward_id' => $row['reward_id'],
                'reward_title' => $row['reward_title'],
            ];
        }
        $result->free();
    }

    $files = [];
    if (!empty($videoalert_path) && is_dir($videoalert_path)) {
        $videoalert_files = array_values(array_diff(scandir($videoalert_path) ?: [], ['.', '..']));
        foreach ($videoalert_files as $file) {
            $rewardId = $videoAlertMappings[$file] ?? null;
            $files[] = [
                'file' => $file,
                'name' => pathinfo($file, PATHINFO_FILENAME),
                'reward_id' => $rewardId,
            ];
        }
    }

    return [
        'success' => true,
        'storage' => [
            'used_mb' => round(($current_storage_used ?? 0) / 1024 / 1024, 2),
            'max_mb' => round(($max_storage_size ?? 0) / 1024 / 1024, 2),
            'percentage' => round($storage_percentage ?? 0, 2),
        ],
        'files' => $files,
        'rewards' => $rewards,
        'sound_mapped_reward_ids' => $soundMappedRewards,
    ];
}

// List endpoint first so the browser can paint skeletons, then fetch rows.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        echo json_encode(video_alerts_list_payload($db));
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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

$status = '';

// Storage scan only when upload/delete need paths + quotas.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_FILES['filesToUpload']) || isset($_POST['delete_files']))) {
    include 'includes/storage_used.php';
}

// Handle channel point reward mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['video_file'], $_POST['reward_id'])) {
    $status = "";
    $videoFile = $_POST['video_file'];
    $rewardId = $_POST['reward_id'];
    $videoFile = htmlspecialchars($videoFile);

    // Check if a mapping already exists for this video file
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
                $status .= t('video_alerts_mapping_update_failed', ['file' => $videoFile, 'error' => $stmt->error]) . "<br>";
            } else {
                $status .= t('video_alerts_mapping_updated', ['file' => $videoFile]) . "<br>";
            }
            $stmt->close();
        } else {
            $stmt = $db->prepare("DELETE FROM video_alerts WHERE video_mapping = ?");
            $stmt->bind_param("s", $videoFile);
            if (!$stmt->execute()) {
                $status .= t('video_alerts_mapping_remove_failed', ['file' => $videoFile, 'error' => $stmt->error]) . "<br>";
            } else {
                $status .= t('video_alerts_mapping_removed', ['file' => $videoFile]) . "<br>";
            }
            $stmt->close();
        }
    } else {
        if ($rewardId) {
            $stmt = $db->prepare("INSERT INTO video_alerts (video_mapping, reward_id) VALUES (?, ?)");
            $stmt->bind_param("ss", $videoFile, $rewardId);
            if (!$stmt->execute()) {
                $status .= t('video_alerts_mapping_create_failed', ['file' => $videoFile, 'error' => $stmt->error]) . "<br>";
            } else {
                $status .= t('video_alerts_mapping_created', ['file' => $videoFile]) . "<br>";
            }
            $stmt->close();
        }
    }
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES["filesToUpload"])) {
    $status = "";
    if (empty($_FILES["filesToUpload"]["tmp_name"]) || !$_FILES["filesToUpload"]["tmp_name"]) {
        $status .= t('video_alerts_no_file_received') . "<br>";
    }
    foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
        $errorCode = $_FILES["filesToUpload"]["error"][$key];
        if ($errorCode !== UPLOAD_ERR_OK) {
            $status .= t('video_alerts_upload_error', [
                'file' => htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])),
                'error' => translateUploadError($errorCode)
            ]) . "<br>";
            continue;
        }
        $fileSize = $_FILES["filesToUpload"]["size"][$key];
        if ($current_storage_used + $fileSize > $max_storage_size) {
            $status .= t('video_alerts_upload_storage_limit', [
                'file' => htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key]))
            ]) . "<br>";
            continue;
        }
        $targetFile = $videoalert_path . '/' . basename($_FILES["filesToUpload"]["name"][$key]);
        $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
        if ($fileType != "mp4") {
            $status .= t('video_alerts_upload_only_mp4', [
                'file' => htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key]))
            ]) . "<br>";
            continue;
        }
        if (move_uploaded_file($tmp_name, $targetFile)) {
            $current_storage_used += $fileSize;
            $status .= t('video_alerts_file_uploaded', [
                'file' => htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key]))
            ]) . "<br>";
        } else {
            $status .= t('video_alerts_upload_error', [
                'file' => htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])),
                'error' => ''
            ]) . "<br>";
        }
    }
}

// Handle file deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_files'])) {
    $status = "";
    foreach ($_POST['delete_files'] as $file_to_delete) {
        $filename = basename($file_to_delete);
        $full_path = $videoalert_path . '/' . $filename;
        if (is_file($full_path) && unlink($full_path)) {
            $status .= t('video_alerts_file_deleted', ['file' => htmlspecialchars($filename)]) . "<br>";
            $stmt = $db->prepare("DELETE FROM video_alerts WHERE video_mapping = ?");
            $stmt->bind_param("s", $filename);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $status .= t('video_alerts_mapping_removed', ['file' => htmlspecialchars($filename)]) . "<br>";
                }
            } else {
                $status .= t('video_alerts_mapping_remove_failed', ['file' => htmlspecialchars($filename), 'error' => '']) . "<br>";
            }
            $stmt->close();
        } else {
            $status .= t('video_alerts_file_delete_failed', ['file' => htmlspecialchars($filename)]) . "<br>";
        }
    }
}

// Start output buffering for content
ob_start();
?>
<!-- How-to info panel -->
<div class="sp-card mb-4">
    <div style="display:flex;align-items:flex-start;gap:1rem;padding:1.25rem;">
        <span style="font-size:1.5rem;color:var(--red);flex-shrink:0;margin-top:0.1rem;">
            <i class="fas fa-bell"></i>
        </span>
        <div>
            <p style="margin-bottom:0.5rem;"><strong><?php echo t('video_alerts_howto_title'); ?></strong></p>
            <ul style="margin:0.25rem 0 0 1.25rem;list-style:disc;padding:0;">
                <li style="margin-bottom:0.3rem;">
                    <span class="icon"><i class="fas fa-upload"></i></span>
                    <?php echo t('video_alerts_howto_upload'); ?>
                </li>
                <li style="margin-bottom:0.3rem;">
                    <span class="icon"><i class="fab fa-twitch"></i></span>
                    <?php echo t('video_alerts_howto_rewards'); ?>
                </li>
                <li style="margin-bottom:0.3rem;">
                    <span class="icon"><i class="fas fa-play-circle"></i></span>
                    <?php echo t('video_alerts_howto_play'); ?>
                </li>
                <li style="margin-bottom:0.3rem;">
                    <span class="icon"><i class="fas fa-headphones"></i></span>
                    <?php echo t('video_alerts_howto_overlay'); ?>
                </li>
            </ul>
        </div>
    </div>
</div>
<!-- Upload Card -->
<div class="sp-card mb-4">
    <header class="sp-card-header">
        <p class="sp-card-title">
            <span class="icon mr-2"><i class="fas fa-upload"></i></span>
            <?php echo t('video_alerts_upload_title'); ?>
        </p>
    </header>
    <div class="sp-card-body">
        <!-- Storage Usage Info -->
        <div class="sp-alert sp-alert-info" id="videoStorageHost" style="margin-bottom:1rem;" aria-busy="true">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                <span><i class="fas fa-database" style="margin-right:0.4rem;"></i> <strong><?php echo t('alerts_storage_usage'); ?>:</strong></span>
                <span id="videoStorageText"><span class="sp-skeleton-line w-50" aria-hidden="true"></span></span>
            </div>
            <progress class="progress" id="videoStorageProgress" value="0" max="100" style="width:100%;"></progress>
        </div>
        <?php if (!empty($status)) : ?>
            <div class="sp-alert sp-alert-info sp-notif mb-4">
                <?php echo $status; ?>
            </div>
        <?php endif; ?>
        <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="sp-form-group">
                <label for="filesToUpload" style="display:block;border:2px dashed var(--border);border-radius:var(--radius-lg);padding:1.5rem;text-align:center;cursor:pointer;background:var(--bg-input);transition:border-color var(--transition);color:var(--text-secondary);">
                    <i class="fas fa-cloud-upload-alt" style="font-size:2rem;margin-bottom:0.5rem;display:block;"></i>
                    <span id="file-list"><?php echo t('video_alerts_no_files_selected'); ?></span>
                    <div style="margin-top:0.5rem;font-size:0.8rem;color:var(--text-muted);"><?php echo t('video_alerts_choose_files'); ?></div>
                    <input type="file" name="filesToUpload[]" id="filesToUpload" multiple accept=".mp4" style="display:none;">
                </label>
            </div>
            <!-- Upload Status Container -->
            <div id="uploadStatusContainer" style="display:none;" class="mb-4">
                <div class="sp-alert sp-alert-info">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                        <span>
                            <span class="icon mr-2"><i class="fas fa-spinner fa-pulse"></i></span>
                            <strong id="uploadStatusText"><?php echo t('video_alerts_preparing_upload'); ?></strong>
                        </span>
                        <span id="uploadProgressPercent" style="font-weight:600;">0%</span>
                    </div>
                    <progress id="uploadProgress" value="0" max="100" style="width:100%;height:1.25rem;border-radius:0.5rem;accent-color:var(--blue);">0%</progress>
                </div>
            </div>
            <button class="sp-btn sp-btn-primary" style="width:100%;font-weight:600;font-size:1.05rem;" type="submit" name="submit" id="uploadBtn">
                <span class="icon"><i class="fas fa-upload"></i></span>
                <span id="uploadBtnText"><?php echo t('video_alerts_upload_btn'); ?></span>
            </button>
        </form>
    </div>
</div>
<!-- File Management Card -->
<div class="sp-card">
    <header class="sp-card-header">
        <p class="sp-card-title">
            <span class="icon mr-2"><i class="fas fa-film"></i></span>
            <?php echo t('video_alerts_your_alerts'); ?>
        </p>
        <button class="sp-btn sp-btn-danger sp-btn-sm" id="deleteSelectedBtn" disabled>
            <span class="icon"><i class="fas fa-trash"></i></span>
            <span><?php echo t('video_alerts_delete_selected'); ?></span>
        </button>
    </header>
    <div class="sp-card-body">
        <div id="videoAlertsEmpty" style="text-align:center;padding:3rem 0;display:none;">
            <h2 style="font-size:1rem;color:var(--text-muted);"><?php echo t('video_alerts_no_files_uploaded'); ?></h2>
        </div>
        <form action="" method="POST" id="deleteForm">
            <div class="sp-table-wrap">
                <table class="sp-table" id="videoAlertsTable">
                    <thead>
                        <tr>
                            <th style="width:70px;text-align:center;"><?php echo t('video_alerts_select'); ?></th>
                            <th style="text-align:center;"><?php echo t('video_alerts_file_name'); ?></th>
                            <th style="text-align:center;"><?php echo t('video_alerts_channel_point_reward'); ?></th>
                            <th style="width:80px;text-align:center;"><?php echo t('video_alerts_action'); ?></th>
                            <th style="width:120px;text-align:center;"><?php echo t('video_alerts_test_video'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="videoAlertsTableBody" aria-busy="true">
                        <?php for ($sk = 0; $sk < 5; $sk++): ?>
                        <tr aria-hidden="true">
                            <td style="text-align:center;"><span class="sp-skeleton-badge"></span></td>
                            <td><span class="sp-skeleton-line w-70"></span></td>
                            <td style="text-align:center;"><span class="sp-skeleton-line w-60"></span></td>
                            <td style="text-align:center;"><span class="sp-skeleton-badge"></span></td>
                            <td style="text-align:center;"><span class="sp-skeleton-badge"></span></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" value="Delete Selected" class="sp-btn sp-btn-danger mt-3" name="submit_delete" style="display:none;">
                <span class="icon"><i class="fas fa-trash"></i></span>
                <span><?php echo t('video_alerts_delete_selected'); ?></span>
            </button>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();

// Start output buffering for scripts
ob_start();
?>
<script>
const VA_I18N = {
    notMapped: <?php echo json_encode(t('video_alerts_not_mapped')); ?>,
    removeMapping: <?php echo json_encode(t('video_alerts_remove_mapping')); ?>,
    selectReward: <?php echo json_encode(t('video_alerts_select_reward')); ?>
};

function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function applyVideoStorage(data) {
    var storage = data.storage || {};
    var textEl = document.getElementById('videoStorageText');
    var progEl = document.getElementById('videoStorageProgress');
    var hostEl = document.getElementById('videoStorageHost');
    if (textEl) textEl.textContent = (storage.used_mb || 0) + 'MB / ' + (storage.max_mb || 0) + 'MB (' + (storage.percentage || 0) + '%)';
    if (progEl) progEl.value = storage.percentage || 0;
    if (hostEl) hostEl.setAttribute('aria-busy', 'false');
}

function renderVideoAlertsTable(data) {
    var tbody = document.getElementById('videoAlertsTableBody');
    var emptyEl = document.getElementById('videoAlertsEmpty');
    var formEl = document.getElementById('deleteForm');
    if (!tbody) return;
    tbody.setAttribute('aria-busy', 'false');
    var files = Array.isArray(data.files) ? data.files : [];
    var rewards = Array.isArray(data.rewards) ? data.rewards : [];
    var rewardTitleById = {};
    rewards.forEach(function(reward) {
        rewardTitleById[reward.reward_id] = reward.reward_title;
    });
    var soundMapped = {};
    (data.sound_mapped_reward_ids || []).forEach(function(id) { soundMapped[id] = true; });
    if (!files.length) {
        tbody.innerHTML = '';
        if (emptyEl) emptyEl.style.display = '';
        if (formEl) formEl.style.display = 'none';
        return;
    }
    if (emptyEl) emptyEl.style.display = 'none';
    if (formEl) formEl.style.display = '';
    var mappedByOther = {};
    files.forEach(function(f) {
        if (f.reward_id) mappedByOther[f.reward_id] = true;
    });
    tbody.innerHTML = files.map(function(file) {
        var currentId = file.reward_id || null;
        var currentTitle = currentId ? (rewardTitleById[currentId] || '') : '';
        var mappedHtml = currentId
            ? '<em>' + escapeHtml(currentTitle || VA_I18N.notMapped) + '</em>'
            : '<em>' + escapeHtml(VA_I18N.notMapped) + '</em>';
        var options = '';
        if (currentId) options += '<option value="" style="color:var(--red);">' + escapeHtml(VA_I18N.removeMapping) + '</option>';
        options += '<option value="">' + escapeHtml(VA_I18N.selectReward) + '</option>';
        rewards.forEach(function(reward) {
            var isCurrent = currentId === reward.reward_id;
            var isMapped = (mappedByOther[reward.reward_id] && !isCurrent) || soundMapped[reward.reward_id];
            if (isMapped && !isCurrent) return;
            options += '<option value="' + escapeHtml(reward.reward_id) + '"' + (isCurrent ? ' selected' : '') + '>' + escapeHtml(reward.reward_title) + '</option>';
        });
        return '<tr>' +
            '<td style="text-align:center;vertical-align:middle;">' +
                '<input type="checkbox" name="delete_files[]" value="' + escapeHtml(file.file) + '">' +
            '</td>' +
            '<td style="vertical-align:middle;">' + escapeHtml(file.name) + '</td>' +
            '<td style="text-align:center;vertical-align:middle;">' + mappedHtml +
                '<form action="" method="POST" class="mapping-form" style="margin-top:0.5rem;">' +
                    '<input type="hidden" name="video_file" value="' + escapeHtml(file.file) + '">' +
                    '<select name="reward_id" class="sp-select mapping-select" style="width:100%;">' + options + '</select>' +
                '</form></td>' +
            '<td style="text-align:center;vertical-align:middle;">' +
                '<button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="' + escapeHtml(file.file) + '">' +
                    '<span class="icon"><i class="fas fa-trash"></i></span>' +
                '</button></td>' +
            '<td style="text-align:center;vertical-align:middle;">' +
                '<button type="button" class="test-video sp-btn sp-btn-primary sp-btn-sm" data-file="' + escapeHtml(file.file) + '">' +
                    '<span class="icon"><i class="fas fa-play"></i></span>' +
                '</button></td>' +
            '</tr>';
    }).join('');
}

function renderVideoAlertsError() {
    var tbody = document.getElementById('videoAlertsTableBody');
    if (tbody) {
        tbody.setAttribute('aria-busy', 'false');
        tbody.innerHTML = '';
    }
    var hostEl = document.getElementById('videoStorageHost');
    if (hostEl) hostEl.setAttribute('aria-busy', 'false');
    var emptyEl = document.getElementById('videoAlertsEmpty');
    if (emptyEl) emptyEl.style.display = 'none';
}

function loadVideoAlertsList() {
    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.success) {
                renderVideoAlertsError();
                return;
            }
            applyVideoStorage(data);
            renderVideoAlertsTable(data);
        })
        .catch(renderVideoAlertsError);
}

$(document).ready(function() {
    loadVideoAlertsList();
    // Auto-dismiss status messages after 15 seconds
    if ($('.sp-notif').length) {
        setTimeout(function() {
            $('.sp-notif').fadeOut(500, function() {
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
    // AJAX upload with progress bar
    $('#uploadForm').on('submit', function(e) {
        e.preventDefault();
        var files = $('#filesToUpload')[0].files;
        if (files.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: <?php echo json_encode(t('video_alerts_no_files_selected_title')); ?>,
                text: <?php echo json_encode(t('video_alerts_select_at_least_one')); ?>,
                confirmButtonColor: '#3273dc'
            });
            return;
        }
        var formData = new FormData(this);
        // Show upload status and update UI
        $('#uploadStatusContainer').show();
        $('#uploadStatusText').html('<i class="fas fa-spinner fa-pulse"></i> ' + <?php echo json_encode(t('video_alerts_uploading_files')); ?>.replace(':count', files.length));
        $('#uploadProgressPercent').text('0%');
        $('#uploadProgress').val(0);
        // Update button state
        $('#uploadBtn').prop('disabled', true).addClass('sp-btn-loading');
        $('#uploadBtnText').text(<?php echo json_encode(t('video_alerts_uploading_short')); ?>);
        $.ajax({
            url: '',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        var percentComplete = Math.round((e.loaded / e.total) * 100);
                        $('#uploadProgress').val(percentComplete);
                        $('#uploadProgressPercent').text(percentComplete + '%');
                        if (percentComplete < 100) {
                            $('#uploadStatusText').html('<i class="fas fa-spinner fa-pulse"></i> ' + <?php echo json_encode(t('video_alerts_uploading_progress')); ?>.replace(':percent', percentComplete));
                        } else {
                            $('#uploadStatusText').html('<i class="fas fa-check-circle"></i> ' + <?php echo json_encode(t('video_alerts_processing_files')); ?>);
                        }
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                $('#uploadStatusText').html('<i class="fas fa-check-circle"></i> ' + <?php echo json_encode(t('video_alerts_upload_completed')); ?>);
                $('#uploadProgressPercent').text('100%');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            },
            error: function() {
                $('#uploadStatusContainer').hide();
                $('#uploadBtn').prop('disabled', false).removeClass('sp-btn-loading');
                $('#uploadBtnText').text(<?php echo json_encode(t('video_alerts_upload_btn')); ?>);
                Swal.fire({
                    icon: 'error',
                    title: <?php echo json_encode(t('video_alerts_upload_failed_title')); ?>,
                    text: <?php echo json_encode(t('video_alerts_upload_failed_text')); ?>,
                    confirmButtonColor: '#3273dc'
                });
            }
        });
    });
    // Handle delete selected button
    $('#deleteSelectedBtn').on('click', function() {
        var checkedBoxes = $('input[name="delete_files[]"]:checked');
        if (checkedBoxes.length > 0) {
            Swal.fire({
                title: '<?php echo t('video_alerts_delete_file_title'); ?>',
                text: <?php echo json_encode(t('video_alerts_delete_selected_confirm')); ?>.replace(':count', checkedBoxes.length),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: '<?php echo t('video_alerts_delete_file_confirm_btn'); ?>',
                cancelButtonText: '<?php echo t('video_alerts_delete_file_cancel_btn'); ?>'
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
        $('#file-list').text(fileNames.length ? fileNames.join(', ') : '<?php echo t('video_alerts_no_files_selected'); ?>');
    });
    // Add event listener for mapping select boxes
    $(document).on('change', '.mapping-select', function() {
        const form = $(this).closest('form');
        $.post('', form.serialize(), function(data) {
            location.reload();
        });
    });
    // Single delete button with SweetAlert2
    $(document).on('click', '.delete-single', function() {
        let fileName = $(this).data('file');
        Swal.fire({
            title: '<?php echo t('video_alerts_delete_file_title'); ?>',
            text: '<?php echo t('video_alerts_delete_file_confirm'); ?>'.replace(':file', fileName),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: '<?php echo t('video_alerts_delete_file_confirm_btn'); ?>',
            cancelButtonText: '<?php echo t('video_alerts_delete_file_cancel_btn'); ?>'
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
    $(document).on('click', '.test-video', function() {
        sendStreamEvent("VIDEO_ALERT", this.getAttribute("data-file"));
    });
});
// Function to send a stream event
function sendStreamEvent(eventType, fileName) {
    const xhr = new XMLHttpRequest();
    const url = "/api/notify_event.php";
    const params = `event=${eventType}&video=${encodeURIComponent(fileName)}&channel_name=<?php echo $username; ?>&api_key=<?php echo $api_key; ?>`;
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
</script>
<?php
$scripts = ob_get_clean();
require 'layout.php';
?>
