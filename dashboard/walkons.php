<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
ini_set('max_execution_time', 300);

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('walkons_page_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

function walkons_format_file_name($fileName) {
    return basename($fileName, '.mp3');
}

function walkons_format_file_name_with_ext($fileName) {
    $fileInfo = pathinfo($fileName);
    $name = basename($fileName, '.' . ($fileInfo['extension'] ?? ''));
    $extension = strtoupper($fileInfo['extension'] ?? '');
    return $name . " (" . $extension . ")";
}

function walkons_list_files($walkon_path) {
    $files = [];
    if (!is_dir($walkon_path)) {
        return $files;
    }
    foreach (array_diff(scandir($walkon_path), array('.', '..')) as $file) {
        $files[] = [
            'name' => $file,
            'display' => walkons_format_file_name_with_ext($file),
            'test_name' => walkons_format_file_name($file),
        ];
    }
    return $files;
}

$stmt = $db->prepare("SELECT timezone, media_migrated FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc() ?: [];
$timezone = $channelData['timezone'] ?? 'UTC';
$media_migrated = (bool)($channelData['media_migrated'] ?? false);
$stmt->close();
date_default_timezone_set($timezone);

// Unified media library channels manage walk-ons via media.php (walkons table +
// /var/www/media/). The legacy page still writes to /var/www/walkons/ only and
// never creates walkons rows, so the bot cannot resolve files after migration.
if ($media_migrated) {
    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'migrated' => true]);
        exit();
    }
    header('Location: media.php?from=walkons');
    exit;
}

// List endpoint first so the browser can paint skeletons, then fetch rows.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        include 'includes/storage_used.php';
        echo json_encode([
            'success' => true,
            'files' => walkons_list_files($walkon_path),
            'storage_used' => (int) $current_storage_used,
            'max_storage' => (int) $max_storage_size,
            'storage_percentage' => (float) $storage_percentage,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Define empty variable for status
$status = '';

// Handle file upload / rename / delete (storage scan only when mutating)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_FILES["filesToUpload"]) || isset($_POST['delete_files']) || isset($_POST['rename_file']))) {
    include 'includes/storage_used.php';
    require_once __DIR__ . '/includes/upload_helpers.php';
    $remaining_storage = $max_storage_size - $current_storage_used;
    $max_upload_size = $remaining_storage;

    if (isset($_FILES["filesToUpload"])) {
        foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
            $fileSize = $_FILES["filesToUpload"]["size"][$key];
            if ($current_storage_used + $fileSize > $max_storage_size) {
                $status .= t('walkons_upload_failed_storage', [htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key]))]) . "<br>";
                continue;
            }
            $targetFile = $walkon_path . '/' . basename($_FILES["filesToUpload"]["name"][$key]);
            $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
            if ($fileType != "mp3" && $fileType != "mp4") {
                $status .= t('walkons_upload_failed_filetype', [htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key]))]) . "<br>";
                continue;
            }
            if (move_uploaded_file($tmp_name, $targetFile)) {
                $current_storage_used += $fileSize;
                $status .= t('walkons_upload_success', [htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key]))]) . "<br>";
            } else {
                $status .= t('walkons_upload_failed_generic', [htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key]))]) . "<br>";
            }
        }
        $storage_percentage = ($current_storage_used / $max_storage_size) * 100; // Update percentage after upload
    }

    if (isset($_POST['rename_file'])) {
        $result = upload_rename_file($walkon_path, $_POST['rename_file'], $_POST['new_name'] ?? '', 'login');
        if (!empty($result['ok'])) {
            $status .= t('upload_rename_success', [htmlspecialchars($result['new_base'])]) . "<br>";
        } else {
            $status .= htmlspecialchars(upload_rename_error_message($result['error'] ?? 'failed')) . "<br>";
        }
        if (upload_rename_is_ajax()) {
            upload_rename_json($result);
        }
    }

    if (isset($_POST['delete_files'])) {
        foreach ($_POST['delete_files'] as $file_to_delete) {
            $file_to_delete = $walkon_path . '/' . basename($file_to_delete);
            if (is_file($file_to_delete) && unlink($file_to_delete)) {
                $status .= t('walkons_delete_success', [htmlspecialchars(basename($file_to_delete))]) . "<br>";
            } else {
                $status .= t('walkons_delete_failed', [htmlspecialchars(basename($file_to_delete))]) . "<br>";
            }
        }
        $current_storage_used = calculateStorageUsed([$walkon_path, $soundalert_path]);
        $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
    }
}

// Start output buffering for layout template (skeletons; JS fills the list)
ob_start();
?>
<!-- Setup info banner -->
<div class="sp-alert sp-alert-danger" style="display:flex; align-items:center; gap:1.25rem; margin-bottom:1.5rem;">
    <i class="fas fa-volume-up fa-2x" style="flex-shrink:0;"></i>
    <div>
        <p style="font-weight:700; margin-bottom:0.5rem;"><?php echo t('walkons_setup_title'); ?></p>
        <ul style="list-style:disc; padding-left:1.25rem; margin:0;">
            <li style="margin-bottom:0.25rem;">
                <i class="fas fa-upload" style="margin-right:0.35rem;"></i>
                <?php echo t('walkons_upload_instruction'); ?>
            </li>
            <li>
                <i class="fas fa-headphones" style="margin-right:0.35rem;"></i>
                <?php echo t('walkons_overlay_instruction'); ?>
            </li>
        </ul>
    </div>
</div>
<!-- Upload Card -->
<div class="sp-card">
    <header class="sp-card-header">
        <p class="sp-card-title">
            <i class="fas fa-upload"></i>
            <?php echo t('walkons_upload_title'); ?>
        </p>
    </header>
    <div class="sp-card-body">
        <!-- Storage Usage Info -->
        <div class="sp-alert sp-alert-info" id="walkonsStorageBar" aria-busy="true" style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                <span><i class="fas fa-database" style="margin-right:0.4rem;"></i> <strong><?php echo t('alerts_storage_usage'); ?>:</strong></span>
                <span id="walkonsStorageText"><span class="sp-skeleton-line w-40" aria-hidden="true"></span></span>
            </div>
            <progress class="progress" id="walkonsStorageProgress" value="0" max="100" style="width:100%;"></progress>
        </div>
        <?php if (!empty($status)) : ?>
            <div class="sp-alert sp-alert-info" id="uploadStatusMsg" style="margin-bottom:1rem;">
                <?php echo $status; ?>
            </div>
        <?php endif; ?>
        <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
            <!-- File chooser -->
            <div style="margin-bottom:0.75rem;">
                <label for="filesToUpload" id="fileDropLabel" style="display:block; border:2px dashed var(--border); border-radius:var(--radius); padding:1.75rem 1.25rem; text-align:center; cursor:pointer; background:var(--bg-input); transition:border-color var(--transition);">
                    <input type="file" name="filesToUpload[]" id="filesToUpload" multiple accept=".mp3,.mp4" style="display:none;">
                    <i class="fas fa-cloud-upload-alt" style="font-size:1.75rem; color:var(--text-muted); margin-bottom:0.5rem; display:block;"></i>
                    <span style="font-weight:600; color:var(--text-secondary);"><?php echo t('walkons_choose_files'); ?></span><br>
                    <span id="file-list" style="font-size:0.82rem; color:var(--text-muted);"><?php echo t('walkons_no_files_selected'); ?></span>
                </label>
            </div>
            <!-- Upload Status Container -->
            <div id="uploadStatusContainer" style="display:none; margin-bottom:0.75rem;">
                <div class="sp-alert sp-alert-info">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                        <strong id="uploadStatusText"><?php echo t('walkons_preparing_upload'); ?></strong>
                        <span id="uploadProgressPercent" style="font-weight:600;">0%</span>
                    </div>
                    <progress class="progress progress-info" id="uploadProgress" value="0" max="100" style="height:1.25rem; border-radius:0.625rem;">0%</progress>
                </div>
            </div>
            <button class="sp-btn sp-btn-primary" type="submit" name="submit" id="uploadBtn" style="width:100%; font-size:1rem; padding:0.65rem 1.1rem;">
                <i class="fas fa-upload"></i>
                <span id="uploadBtnText"><?php echo t('walkons_upload_btn'); ?></span>
            </button>
        </form>
    </div>
</div>
<!-- File Management Card -->
<div class="sp-card">
    <header class="sp-card-header">
        <p class="sp-card-title">
            <i class="fas fa-door-open"></i>
            <?php echo t('walkons_users_with_walkons'); ?>
        </p>
        <button class="sp-btn sp-btn-danger" id="deleteSelectedBtn" disabled>
            <i class="fas fa-trash"></i>
            <span><?php echo t('walkons_delete_selected'); ?></span>
        </button>
    </header>
    <div class="sp-card-body" id="walkonsListHost" aria-busy="true">
        <form action="" method="POST" id="deleteForm">
            <div class="sp-table-wrap" id="walkonsTableWrap">
                <table class="sp-table" id="walkonsTable">
                    <thead>
                        <tr>
                            <th style="width:70px; text-align:center;"><?php echo t('walkons_select'); ?></th>
                            <th style="text-align:center;"><?php echo t('walkons_file_name'); ?></th>
                            <th style="width:150px; text-align:center;"><?php echo t('walkons_action'); ?></th>
                            <th style="width:150px; text-align:center;"><?php echo t('walkons_test_audio'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="walkonsTableBody">
                        <?php for ($sk = 0; $sk < 5; $sk++): ?>
                        <tr aria-hidden="true">
                            <td style="text-align:center;"><span class="sp-skeleton-line w-40"></span></td>
                            <td><span class="sp-skeleton-line w-80"></span></td>
                            <td style="text-align:center;"><span class="sp-skeleton-badge"></span></td>
                            <td style="text-align:center;"><span class="sp-skeleton-line w-50"></span></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" value="Delete Selected" class="sp-btn sp-btn-danger" name="submit_delete" style="display:none;">
                <i class="fas fa-trash"></i>
                <span><?php echo t('walkons_delete_selected'); ?></span>
            </button>
        </form>
        <div id="walkonsEmptyState" style="text-align:center; padding:3rem 0; display:none;">
            <p style="font-size:1.05rem; color:var(--text-muted);"><?php echo t('walkons_no_files_uploaded'); ?></p>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
$(document).ready(function() {
    // Auto-dismiss status messages after 15 seconds
    if ($('#uploadStatusMsg').length) {
        setTimeout(function() {
            $('#uploadStatusMsg').fadeOut(500, function() {
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
                title: <?php echo json_encode(t('walkons_no_files_title')); ?>,
                text: <?php echo json_encode(t('walkons_no_files_text')); ?>,
                confirmButtonColor: '#3273dc'
            });
            return;
        }
        var formData = new FormData(this);
        // Show upload status and update UI
        $('#uploadStatusContainer').show();
        $('#uploadStatusText').html('<i class="fas fa-spinner fa-pulse"></i> ' + <?php echo json_encode(t('walkons_uploading_files')); ?>.replace('%s', files.length));
        $('#uploadProgressPercent').text('0%');
        $('#uploadProgress').val(0);
        // Update button state
        $('#uploadBtn').prop('disabled', true).addClass('sp-btn-loading');
        $('#uploadBtnText').text(<?php echo json_encode(t('walkons_uploading')); ?>);
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
                            $('#uploadStatusText').html('<i class="fas fa-spinner fa-pulse"></i> ' + <?php echo json_encode(t('walkons_uploading_percent')); ?>.replace('%s', percentComplete));
                        } else {
                            $('#uploadStatusText').html('<i class="fas fa-check-circle"></i> ' + <?php echo json_encode(t('walkons_processing_files')); ?>);
                        }
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                $('#uploadStatusText').html('<i class="fas fa-check-circle"></i> ' + <?php echo json_encode(t('walkons_upload_complete')); ?>);
                $('#uploadProgressPercent').text('100%');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            },
            error: function() {
                $('#uploadStatusContainer').hide();
                $('#uploadBtn').prop('disabled', false).removeClass('sp-btn-loading');
                $('#uploadBtnText').text(<?php echo json_encode(t('walkons_upload_btn')); ?>);
                Swal.fire({
                    icon: 'error',
                    title: <?php echo json_encode(t('walkons_upload_failed_title')); ?>,
                    text: <?php echo json_encode(t('walkons_upload_error')); ?>,
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
                title: '<?php echo t('walkons_delete_file_confirm_title'); ?>',
                text: <?php echo json_encode(t('walkons_delete_selected_confirm')); ?>.replace('%s', checkedBoxes.length),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: '<?php echo t('walkons_delete_file_confirm_btn'); ?>',
                cancelButtonText: '<?php echo t('walkons_delete_file_cancel_btn'); ?>'
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
        $('#file-list').text(fileNames.length ? fileNames.join(', ') : '<?php echo t('walkons_no_files_selected'); ?>');
    });
    $(document).on('click', '.rename-single', function() {
        var fileName = $(this).data('file');
        if (!fileName || typeof specterPromptRename !== 'function') return;
        specterPromptRename({
            currentName: fileName,
            title: WALKONS_I18N.renameTitle,
            hint: WALKONS_I18N.renameHint,
            confirmText: WALKONS_I18N.renameConfirm,
            cancelText: WALKONS_I18N.renameCancel,
            emptyError: WALKONS_I18N.renameEmpty
        }).then(function(nextName) {
            if (!nextName) return;
            return specterPostRename(window.location.pathname, {
                rename_file: fileName,
                new_name: nextName
            }).then(function(data) {
                if (data && data.success) {
                    loadWalkons();
                } else {
                    Swal.fire({ icon: 'error', title: WALKONS_I18N.failed, text: specterRenameMessage(data, WALKONS_I18N) });
                }
            });
        }).catch(function() {
            Swal.fire({ icon: 'error', title: WALKONS_I18N.failed, text: WALKONS_I18N.failed });
        });
    });
    // Single delete button with SweetAlert2 (delegated for AJAX rows)
    $(document).on('click', '.delete-single', function() {
        let fileName = $(this).data('file');
        Swal.fire({
            title: '<?php echo t('walkons_delete_file_confirm_title'); ?>',
            text: '<?php echo t('walkons_delete_file_confirm'); ?>'.replace(':file', fileName),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: '<?php echo t('walkons_delete_file_confirm_btn'); ?>',
            cancelButtonText: '<?php echo t('walkons_delete_file_cancel_btn'); ?>'
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
    $(document).on('click', '.test-walkon', function() {
        sendStreamEvent("WALKON", $(this).attr('data-file'));
    });
    loadWalkons();
});

var WALKONS_I18N = {
    empty: <?php echo json_encode(t('walkons_no_files_uploaded')); ?>,
    loadError: <?php echo json_encode(t('walkons_upload_error')); ?>,
    rename: <?php echo json_encode(t('upload_rename')); ?>,
    renameTitle: <?php echo json_encode(t('upload_rename_title')); ?>,
    renameHint: <?php echo json_encode(t('upload_rename_hint_walkon')); ?>,
    renameConfirm: <?php echo json_encode(t('upload_rename_confirm')); ?>,
    renameCancel: <?php echo json_encode(t('upload_rename_cancel')); ?>,
    renameEmpty: <?php echo json_encode(t('upload_rename_empty')); ?>,
    success: <?php echo json_encode(t('upload_rename_success')); ?>,
    failed: <?php echo json_encode(t('upload_rename_failed')); ?>,
    exists: <?php echo json_encode(t('upload_rename_exists')); ?>,
    invalid: <?php echo json_encode(t('upload_rename_invalid')); ?>,
    missing: <?php echo json_encode(t('upload_rename_missing')); ?>,
    same: <?php echo json_encode(t('upload_rename_same')); ?>
};

function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function applyWalkonsStorage(data) {
    if (!data || typeof data.storage_used !== 'number' || typeof data.max_storage !== 'number') return;
    var usedMb = (data.storage_used / 1024 / 1024).toFixed(2);
    var maxMb = (data.max_storage / 1024 / 1024).toFixed(2);
    var pct = typeof data.storage_percentage === 'number'
        ? data.storage_percentage.toFixed(2)
        : ((data.storage_used / data.max_storage) * 100).toFixed(2);
    var textEl = document.getElementById('walkonsStorageText');
    var progEl = document.getElementById('walkonsStorageProgress');
    var barEl = document.getElementById('walkonsStorageBar');
    if (textEl) textEl.textContent = usedMb + 'MB / ' + maxMb + 'MB (' + pct + '%)';
    if (progEl) progEl.value = pct;
    if (barEl) barEl.setAttribute('aria-busy', 'false');
}

function renderWalkonsTable(files) {
    var tbody = document.getElementById('walkonsTableBody');
    var wrap = document.getElementById('walkonsTableWrap');
    var empty = document.getElementById('walkonsEmptyState');
    var host = document.getElementById('walkonsListHost');
    var deleteBtn = document.getElementById('deleteSelectedBtn');
    if (host) host.setAttribute('aria-busy', 'false');
    if (deleteBtn) deleteBtn.disabled = true;
    if (!files.length) {
        if (wrap) wrap.style.display = 'none';
        if (empty) empty.style.display = '';
        if (tbody) tbody.innerHTML = '';
        return;
    }
    if (wrap) wrap.style.display = '';
    if (empty) empty.style.display = 'none';
    if (!tbody) return;
    tbody.innerHTML = files.map(function(file) {
        var name = file.name || '';
        var display = file.display || name;
        var testName = file.test_name || name;
        return '<tr>' +
            '<td style="text-align:center;">' +
                '<input type="checkbox" name="delete_files[]" value="' + escapeHtml(name) + '">' +
            '</td>' +
            '<td>' + escapeHtml(display) + '</td>' +
            '<td style="text-align:center;">' +
                '<span class="file-row-actions">' +
                '<button type="button" class="rename-single sp-btn sp-btn-secondary sp-btn-sm" data-file="' + escapeHtml(name) + '" title="' + escapeHtml(WALKONS_I18N.rename) + '">' +
                    '<i class="fas fa-pencil-alt"></i>' +
                '</button>' +
                '<button type="button" class="delete-single sp-btn sp-btn-danger sp-btn-sm" data-file="' + escapeHtml(name) + '">' +
                    '<i class="fas fa-trash"></i>' +
                '</button></span>' +
            '</td>' +
            '<td style="text-align:center;">' +
                '<button type="button" class="test-walkon sp-btn sp-btn-primary sp-btn-sm" data-file="' + escapeHtml(testName) + '">' +
                    '<i class="fas fa-play"></i>' +
                '</button>' +
            '</td>' +
        '</tr>';
    }).join('');
}

function renderWalkonsError() {
    var tbody = document.getElementById('walkonsTableBody');
    var wrap = document.getElementById('walkonsTableWrap');
    var empty = document.getElementById('walkonsEmptyState');
    var host = document.getElementById('walkonsListHost');
    var barEl = document.getElementById('walkonsStorageBar');
    if (host) host.setAttribute('aria-busy', 'false');
    if (barEl) barEl.setAttribute('aria-busy', 'false');
    if (empty) empty.style.display = 'none';
    if (wrap) wrap.style.display = '';
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">' + escapeHtml(WALKONS_I18N.loadError) + '</td></tr>';
    }
}

function loadWalkons() {
    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.migrated) {
                window.location.href = 'media.php?from=walkons';
                return;
            }
            if (!data || !data.success) {
                renderWalkonsError();
                return;
            }
            applyWalkonsStorage(data);
            renderWalkonsTable(Array.isArray(data.files) ? data.files : []);
        })
        .catch(function() {
            renderWalkonsError();
        });
}

// Function to send a stream event
function sendStreamEvent(eventType, fileName) {
    const xhr = new XMLHttpRequest();
    const url = "/api/notify_event.php";
    const params = `event=${eventType}&user=${encodeURIComponent(fileName)}&channel=<?php echo $username; ?>&api_key=<?php echo $api_key; ?>`;
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
